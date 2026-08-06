<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use Hyperdigital\HdTranslator\Helpers\TranslationHelper;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Answers "how much of this is actually translated" for both halves of the module.
 *
 * The module could say which strings and which records exist, but not how far a language had got.
 * That is the question asked before a release, and the one a project manager asks when deciding
 * whether a language is ready to go live.
 *
 * Coverage of the static strings is counted from the XLF files themselves rather than through
 * LanguageService, because the label lookup falls back to the source language: an untranslated
 * key comes back as its english text, which is indistinguishable from a translated one.
 */
class TranslationCoverageService
{
    /**
     * Depth the page tree of a site is walked when collecting the pages its records live on.
     */
    protected const MAX_TREE_DEPTH = 99;

    public function __construct(protected readonly XlfService $xlfService) {}

    /**
     * Coverage of the registered static string files.
     *
     * @return array<int, array{key: string, label: string, category: string, total: int, languages: array<int, array{language: string, translated: int, total: int, percent: int}>}>
     */
    public function getStaticStringCoverage(): array
    {
        $registry = $GLOBALS['TYPO3_CONF_VARS']['translator'] ?? [];
        if (empty($registry)) {
            return [];
        }

        $storage = TranslationHelper::getStoragePath();
        $return = [];

        foreach ($registry as $key => $settings) {
            $sourceKeys = $this->readKeysOfFile(GeneralUtility::getFileAbsFileName((string)($settings['path'] ?? '')));
            $total = count($sourceKeys);

            $languages = [];
            foreach (($settings['languages'] ?? []) as $language) {
                if ($language === 'en' || $language === 'default') {
                    // the source language is complete by definition
                    continue;
                }

                $translated = $this->countTranslatedKeys($sourceKeys, (string)($settings['path'] ?? ''), (string)$language, $storage, (string)$key);

                $languages[] = [
                    'language' => (string)$language,
                    'translated' => $translated,
                    'total' => $total,
                    'percent' => $total > 0 ? (int)round($translated / $total * 100) : 0,
                ];
            }

            $return[] = [
                'key' => (string)$key,
                'label' => (string)($settings['label'] ?? $key),
                'category' => (string)($settings['category'] ?? '-'),
                'total' => $total,
                'languages' => $languages,
            ];
        }

        return $return;
    }

    /**
     * Coverage of the translatable records of a site.
     *
     * @param array<int, string> $tables
     * @return array<int, array{languageId: int, title: string, tables: array<int, array{table: string, title: string, default: int, translated: int, percent: int}>, default: int, translated: int, percent: int}>
     */
    public function getDatabaseCoverage(Site $site, array $tables): array
    {
        $pages = TranslationHelper::getPagesOfSite($site, self::MAX_TREE_DEPTH);
        if (empty($pages)) {
            return [];
        }

        $return = [];

        foreach ($site->getAllLanguages() as $siteLanguage) {
            $languageId = $siteLanguage->getLanguageId();
            if ($languageId === 0) {
                continue;
            }

            $rows = [];
            $siteDefault = 0;
            $siteTranslated = 0;

            foreach ($tables as $table) {
                $default = $this->countRecords((string)$table, $pages, 0);
                $translated = $this->countRecords((string)$table, $pages, $languageId);

                if ($default === 0 && $translated === 0) {
                    // a table without content in this page tree says nothing, leave it out
                    continue;
                }

                $siteDefault += $default;
                $siteTranslated += $translated;

                $rows[] = [
                    'table' => (string)$table,
                    'title' => (string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table),
                    'default' => $default,
                    'translated' => $translated,
                    'percent' => $default > 0 ? (int)round(min($translated, $default) / $default * 100) : 0,
                ];
            }

            $return[] = [
                'languageId' => $languageId,
                'title' => $siteLanguage->getTitle() ?: ('Language ' . $languageId),
                'tables' => $rows,
                'default' => $siteDefault,
                'translated' => $siteTranslated,
                'percent' => $siteDefault > 0 ? (int)round(min($siteTranslated, $siteDefault) / $siteDefault * 100) : 0,
            ];
        }

        return $return;
    }

    /**
     * Keys of an XLF file, empty when the file does not exist.
     *
     * @return array<int, string>
     */
    protected function readKeysOfFile(string $absolutePath): array
    {
        if ($absolutePath === '' || !is_readable($absolutePath)) {
            return [];
        }

        return array_keys($this->xlfService->parse((string)file_get_contents($absolutePath)));
    }

    /**
     * How many of the source keys have a non empty translation, looking both at the file the
     * extension ships next to the original and at the override this module writes.
     *
     * @param array<int, string> $sourceKeys
     */
    protected function countTranslatedKeys(array $sourceKeys, string $sourcePath, string $language, string|false $storage, string $key): int
    {
        if (empty($sourceKeys)) {
            return 0;
        }

        $translated = [];

        foreach ($this->getTranslationFileCandidates($sourcePath, $language, $storage, $key) as $candidate) {
            if (!is_readable($candidate)) {
                continue;
            }

            foreach ($this->xlfService->parse((string)file_get_contents($candidate)) as $entryKey => $entry) {
                if (trim($entry['target']) !== '') {
                    $translated[$entryKey] = true;
                }
            }
        }

        return count(array_intersect($sourceKeys, array_keys($translated)));
    }

    /**
     * @return array<int, string> absolute paths, in the order they override each other
     */
    protected function getTranslationFileCandidates(string $sourcePath, string $language, string|false $storage, string $key): array
    {
        $candidates = [];

        $absoluteSource = GeneralUtility::getFileAbsFileName($sourcePath);
        if ($absoluteSource !== '') {
            // TYPO3 convention: "de.locallang.xlf" next to "locallang.xlf"
            $candidates[] = dirname($absoluteSource) . '/' . $language . '.' . basename($absoluteSource);
        }

        if ($storage !== false) {
            $candidates[] = $storage . $language . '.' . $key . '.xlf';
        }

        return $candidates;
    }

    /**
     * @param array<int, int> $pages
     */
    protected function countRecords(string $table, array $pages, int $languageId): int
    {
        if (empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
            return 0;
        }

        $languageField = (string)$GLOBALS['TCA'][$table]['ctrl']['languageField'];
        $queryBuilder = $this->getQueryBuilder($table);

        $constraints = [
            $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pages, Connection::PARAM_INT_ARRAY)),
            $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
        ];

        // pages of a site live under their root page, the root page itself has the site as parent
        if ($table === 'pages') {
            $constraints[0] = $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($pages, Connection::PARAM_INT_ARRAY)
            );
            if ($languageId > 0 && !empty($GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'])) {
                // a translated page is a row of its own, its uid is not in the default language list
                $constraints[0] = $queryBuilder->expr()->in(
                    (string)$GLOBALS['TCA']['pages']['ctrl']['transOrigPointerField'],
                    $queryBuilder->createNamedParameter($pages, Connection::PARAM_INT_ARRAY)
                );
            }
        }

        return (int)$queryBuilder
            ->count('uid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchOne();
    }

    protected function getQueryBuilder(string $table)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder;
    }
}

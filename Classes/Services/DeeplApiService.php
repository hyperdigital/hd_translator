<?php
namespace Hyperdigital\HdTranslator\Services;


use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DeeplApiService
{
    /**
     * How large a request to DeepL may get. DeepL limits a call by the size of the body, not by
     * the number of texts in it, and that limit is 128 KiB. The budget below leaves room for the
     * rest of the body and for the request growing while it is encoded.
     *
     * @see https://developers.deepl.com/api-reference/translate/request-translation
     */
    protected const DEEPL_MAX_REQUEST_BYTES = 100000;

    /**
     * @var string Deepl api version - https://developers.deepl.com/docs/getting-started/auth
     */
    protected $version = 'v2';

    /**
     * @var string Deepl api endpoint - https://developers.deepl.com/docs/getting-started/auth
     */
    protected $baseUrl = '';

    /**
     * @var string Deepl Api key - set over extension settings
     */
    protected $deeplApiKey = '';

    public function __construct(string $deeplApiKey = '' )
    {
        if (empty($deeplApiKey)) {
            $this->deeplApiKey = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'deeplApiKey') ?? '';
        } else {
            $this->deeplApiKey = $deeplApiKey;
        }

        if (substr($this->deeplApiKey, -3) == ':fx') {
            // Free version
            $this->baseUrl = 'https://api-free.deepl.com/'.$this->version.'/';
        } else {
            // Pro version
            $this->baseUrl = 'https://api.deepl.com/'.$this->version.'/';
        }
    }

    /**
     * @return bool true when an api key is configured
     */
    public function isEnabled(): bool
    {
        return !empty($this->deeplApiKey);
    }

    /**
     * Checks the given code against the languages synchronized from DeepL.
     *
     * @param string $language
     * @return bool
     */
    public function isSupportedLanguage(string $language): bool
    {
        $language = trim($language);
        if ($language === '') {
            return false;
        }

        return !empty($this->getLanguageByCode(strtoupper($language)));
    }

    /**
     * @throws \RuntimeException when DeepL cannot be reached or answers with an error
     */
    public function syncAvailableLanguages()
    {
        if (!empty($this->deeplApiKey)) {
            // otherwise, proxy DeepL
            $url = $this->baseUrl . 'languages?' . http_build_query([
                    'type' => 'target',
                ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [$this->getAuthorizationHeader()]);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200 || $response === false) {
                throw new \RuntimeException(
                    'DeepL API error (' . $httpCode . '): ' . $curlError . ' ' . (is_string($response) ? $response : ''),
                    1716200001
                );
            }

            $response = json_decode($response, true);
            if (!is_array($response)) {
                throw new \RuntimeException('DeepL API returned an unexpected response', 1716200002);
            }

            foreach ($response as $row) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();

                $code = trim((string)($row['language'] ?? ''));
                $name = trim((string)($row['name']     ?? ''));

                if ($code === '' || $name === '') {
                    continue;
                }

                // 1) Check existence
                $existingUid = $queryBuilder
                    ->select('uid')
                    ->from('tx_hdtranslator_ai_languages')
                    ->where(
                        $queryBuilder->expr()->eq(
                            'language',
                            $queryBuilder->createNamedParameter($code)
                        )
                    )
                    ->executeQuery()->fetchAssociative();
                if ($existingUid && $existingUid['uid']) {
                    // 2a) Update
                    $queryBuilder
                        ->update('tx_hdtranslator_ai_languages')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'uid',
                                $existingUid['uid']
                            )
                        )
                        ->set(
                            'name',
                            $name
                        )
                        ->executeStatement();
                } else {
                    // 2b) Insert
                    $queryBuilder
                        ->insert('tx_hdtranslator_ai_languages')
                        ->values([
                            'language' => $code,
                            'name' => $name
                        ])
                        ->executeStatement();
                }
            }
        }
    }

    public function getAvailableLanguages($cleaned = false)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();
        $select = ['*'];
        if ($cleaned) {
            $select = ['uid', 'language', 'name'];
        }
        $result = $queryBuilder
            ->select(...$select)
            ->from('tx_hdtranslator_ai_languages')
            ->orderBy('language')
            ->executeQuery();

        return $result->fetchAllAssociative();
    }

    public function getLanguageByCode($code)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();
        $select = ['*'];

        $result = $queryBuilder
            ->select(...$select)
            ->from('tx_hdtranslator_ai_languages')
            ->orderBy('language')
            ->where(
                $queryBuilder->expr()->eq('language', $queryBuilder->createNamedParameter($code))
            )
            ->executeQuery();

        return $result->fetchAssociative();
    }

    public function getAvailableLanguagesWithAmounts($cleaned = false)
    {
        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $qb */
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_hdtranslator_ai_languages')
            ->createQueryBuilder();

// 1) Select your normal columns…
        $qb->select('l.uid', 'l.language', 'l.name')
// 2) …then add the COUNT() as a literal SQL fragment
            ->addSelectLiteral('COUNT(t.uid) AS translations_count')
// 3) From your languages table (aliased as "l")
            ->from('tx_hdtranslator_ai_languages', 'l')
// 4) LEFT JOIN onto your translations table (aliased as "t")
            ->leftJoin(
                'l',
                'tx_hdtranslator_ai_translation',
                't',
                $qb->expr()->eq('t.target_language', 'l.language')
            )
// 5) Group and order
            ->groupBy('l.uid')
            ->orderBy('l.language');
        $result = $qb->executeQuery();
        return $result->fetchAllAssociative();
    }

    public function getLocalTranslation($t, $targetLanguage)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslation = $queryBuilder
            ->select('translation')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($targetLanguage)
                ),
                $queryBuilder->expr()->eq(
                    'original_source',
                    $queryBuilder->createNamedParameter($t)
                )
            )
            ->executeQuery()->fetchAssociative();

        if ($existingTranslation) {
            return $existingTranslation['translation'];
        }

        return false;
    }

    /**
     * @throws \RuntimeException when DeepL cannot be reached or answers with an error
     */
    /**
     * DeepL removed the legacy "auth_key" parameter in November 2025 and answers requests
     * carrying it with 403. The key goes into this header instead.
     *
     * @see https://developers.deepl.com/docs/resources/breaking-changes-change-notices/november-2025-deprecation-of-legacy-auth-methods
     */
    protected function getAuthorizationHeader(): string
    {
        return 'Authorization: DeepL-Auth-Key ' . $this->deeplApiKey;
    }

    public function deeplPost($postData)
    {
        // A caller may still hand over a body built the old way; the key never belongs
        // into the payload any more, so it is stripped rather than sent and rejected.
        $postData = ltrim((string)preg_replace('/(^|&)auth_key=[^&]*/', '', (string)$postData), '&');

        $ch = curl_init($this->baseUrl . 'translate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
            $this->getAuthorizationHeader(),
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException(
                // the body carries DeepL's own reason, which is the difference between
                // "quota exhausted" and "this authentication method no longer exists"
                'Failed to contact DeepL (' . $httpCode . '): ' . $curlError . ' ' . (is_string($response) ? $response : ''),
                1716200003
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['translations']) || !is_array($data['translations'])) {
            throw new \RuntimeException('DeepL returned an unexpected response', 1716200004);
        }

        return $data;
    }

    /**
     * Writes a whole batch of fresh translations in one statement.
     *
     * @param array<string, string> $translations source text => translation
     */
    public function setLocalTranslations(array $translations, string $targetLanguage): void
    {
        if ($translations === []) {
            return;
        }

        $rows = [];
        foreach ($translations as $source => $translation) {
            $rows[] = [
                'target_language' => $targetLanguage,
                'original_source' => (string)$source,
                'original_translation' => $translation,
                'translation' => $translation,
            ];
        }

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_hdtranslator_ai_translation')
            ->bulkInsert(
                'tx_hdtranslator_ai_translation',
                $rows,
                ['target_language', 'original_source', 'original_translation', 'translation']
            );
    }

    public function setLocalTranslation($source, $translation, $targetLanguage)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $queryBuilder
            ->insert('tx_hdtranslator_ai_translation')
            ->values([
                'target_language' => $targetLanguage,
                'original_source' => $source,
                'original_translation' => $translation,
                'translation' => $translation
            ])
            ->executeStatement();
    }

    public function translateTexts(array $texts, string $targetLanguage): array
    {
        $texts = array_map('strval', $texts);
        $unique = array_values(array_unique($texts));

        // "26", "|", "<" carry no language. DeepL would answer them unchanged and charge for
        // the characters, so they never leave the server.
        $translatable = array_values(array_filter(
            $unique,
            static fn(string $text): bool => preg_match('/\p{L}/u', $text) === 1
        ));

        // one query for the whole batch, not one per string
        $translations = $this->getLocalTranslations($translatable, $targetLanguage);

        $toTranslate = [];
        foreach ($translatable as $text) {
            if (!isset($translations[$text])) {
                $toTranslate[] = $text;
            }
        }

        if ($toTranslate !== []) {
            $fresh = [];

            foreach ($this->chunkForDeepl($toTranslate, $targetLanguage) as [$chunk, $postData]) {
                $data = $this->deeplPost($postData);

                // DeepL answers in the order it was given, but a shorter response must not
                // shift the mapping, so anything unmatched is left out
                foreach (array_values($data['translations']) as $i => $translation) {
                    if (!isset($chunk[$i])) {
                        break;
                    }

                    $source = $chunk[$i];
                    $translated = $translation['text'] ?? $source;
                    $translations[$source] = $translated;
                    $fresh[$source] = $translated;
                }
            }

            $this->setLocalTranslations($fresh, $targetLanguage);
        }

        // back into the order and multiplicity the caller asked for
        $ordered = [];
        foreach ($texts as $text) {
            $ordered[$text] = ['text' => $translations[$text] ?? $text];
        }

        return $ordered;
    }

    /**
     * Splits the texts into as few calls to DeepL as its request size allows, and hands back the
     * encoded body with each one so it is not built twice.
     *
     * @param string[] $texts
     * @return array<int, array{0: string[], 1: string}>
     */
    protected function chunkForDeepl(array $texts, string $targetLanguage): array
    {
        $base = http_build_query(['target_lang' => $targetLanguage]);

        $chunks = [];
        $current = [];
        $body = $base;

        foreach ($texts as $text) {
            $encoded = '&text=' . urlencode($text);

            if ($current !== [] && strlen($body) + strlen($encoded) > self::DEEPL_MAX_REQUEST_BYTES) {
                $chunks[] = [$current, $body];
                $current = [];
                $body = $base;
            }

            $current[] = $text;
            $body .= $encoded;
        }

        if ($current !== []) {
            $chunks[] = [$current, $body];
        }

        return $chunks;
    }

    /**
     * Cached translations for a whole batch of strings.
     *
     * The single string variant is kept for callers outside this class; a frontend batch of
     * fifty strings used to mean fifty queries against a table that has no index for them.
     *
     * @param string[] $sources
     * @return array<string, string> source text => translation
     */
    protected function getLocalTranslations(array $sources, string $targetLanguage): array
    {
        if ($sources === []) {
            return [];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_hdtranslator_ai_translation');

        $rows = $queryBuilder
            ->select('original_source', 'translation')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($targetLanguage)
                ),
                $queryBuilder->expr()->in(
                    'original_source',
                    $queryBuilder->createNamedParameter($sources, \TYPO3\CMS\Core\Database\Connection::PARAM_STR_ARRAY)
                )
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $exact = [];
        $caseInsensitive = [];
        foreach ($rows as $row) {
            $source = (string)$row['original_source'];
            $translation = (string)$row['translation'];
            if ($translation === '') {
                continue;
            }
            // the first row wins, the same way a single fetchAssociative() did
            $exact[$source] ??= $translation;
            $caseInsensitive[mb_strtolower($source)] ??= $translation;
        }

        // The collation of the table is case insensitive, so the per string lookup matched
        // "Necessary" against a stored "necessary" too. Keeping that avoids paying DeepL
        // again for strings that only differ in case.
        $return = [];
        foreach ($sources as $source) {
            $translation = $exact[$source] ?? $caseInsensitive[mb_strtolower($source)] ?? null;
            if ($translation !== null) {
                $return[$source] = $translation;
            }
        }

        return $return;
    }

    public function getAllTranslationsForLanguage($language)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($language)
                )
            )
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function getTranslationByUid($uid)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid)
                )
            )
            ->executeQuery()->fetchAssociative();

        return $existingTranslations;
    }

    public function getTranslationsBySource($source)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'original_source',
                    $queryBuilder->createNamedParameter($source)
                )
            )
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function getUniqueOriginals()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->distinct()
            ->from('tx_hdtranslator_ai_translation')
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function removeAllTranslations($language)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $queryBuilder
            ->update('tx_hdtranslator_ai_translation')
            ->set('deleted', 1)
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($language)
                )
            )
            ->executeStatement();

        return true;
    }
}
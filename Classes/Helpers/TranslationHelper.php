<?php
namespace Hyperdigital\HdTranslator\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;

class TranslationHelper
{
    /**
     * Resolves the page a record lives on. Used to determine the site configuration,
     * because the Translator module has no page tree that would supply "id" on its own.
     *
     * @param string $tablename
     * @param int $uid
     * @return int pid of the record, 0 when it cannot be resolved
     */
    public static function getPidOfRecord(string $tablename, int $uid): int
    {
        if ($uid <= 0 || empty($GLOBALS['TCA'][$tablename])) {
            return 0;
        }

        // a page is its own page context
        if ($tablename === 'pages') {
            return $uid;
        }

        $queryBuilder = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable($tablename)
            ->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()
            ->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));

        $row = $queryBuilder
            ->select('pid')
            ->from($tablename)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAssociative();

        return (int)($row['pid'] ?? 0);
    }

    /**
     * Uids of the pages of a site, the root page included.
     *
     * Used wherever something has to be restricted to one site: the coverage numbers and the
     * import endpoint, which refuses records outside the site its reaction is bound to.
     *
     * @return array<int, int>
     */
    public static function getPagesOfSite(\TYPO3\CMS\Core\Site\Entity\Site $site, int $maxDepth = 99): array
    {
        $rootPageId = $site->getRootPageId();
        if ($rootPageId <= 0) {
            return [];
        }

        $pages = [$rootPageId];
        $level = [$rootPageId];

        for ($depth = 0; $depth < $maxDepth && !empty($level); $depth++) {
            $queryBuilder = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
                ->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()
                ->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));

            $rows = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($level, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchFirstColumn();

            $level = array_map('intval', $rows);
            $pages = array_merge($pages, $level);
        }

        return array_values(array_unique($pages));
    }

    public static function getStoragePath()
    {
        $storage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'storagePath');

        if (!empty($storage)){
            $return = Environment::getProjectPath() ;
            if (substr($storage, 0, 1) != '/') {
                $return .= '/';
            }

            $return .= $storage;

            if (substr($return, -1) != '/') {
                $return .= '/';
            }

            $return = str_replace('//', '/', $return);

            if (!file_exists($return)) {
                mkdir($return);
            }

            return $return;
        }

        return false;
    }

    public static function setupTranslation()
    {
        $storage = self::getStoragePath();

        if (\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($storage.'locallangConf.php')) {
                require $storage.'locallangConf.php';
            }
        }

        if ($storage && !empty($GLOBALS['TYPO3_CONF_VARS']['translator'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'] as $key => $settings) {
                foreach ($settings['languages'] as $lang) {
                    if ($lang == 'en' || $lang == 'default') {
                        $filename = $key.'.xlf';
                    } else {
                        $filename = $lang.'.'.$key.'.xlf';
                    }

                    $fileanmePath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($storage . $filename);
                    if (empty($fileanmePath)) {
                        $fileanmePath = $storage . $filename;
                    }

                    if (file_exists($fileanmePath)) {
                        // TYPO3 14 moved this registry from SYS.locallangXMLOverride to
                        // LANG.resourceOverrides. Only the persisted setting is migrated once by
                        // the install tool, a value written at runtime like this one is not, so
                        // writing the old key means the overrides are simply never read.
                        if ($lang == 'en' || $lang == 'default') {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$settings['path']]) || !in_array($fileanmePath, $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$settings['path']][] = $fileanmePath;
                            }
                        } else {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$lang][$settings['path']]) || !in_array($fileanmePath, $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$lang][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['LANG']['resourceOverrides'][$lang][$settings['path']][] = $fileanmePath;
                            }
                        }
                    }
                }
            }
        }
    }
}

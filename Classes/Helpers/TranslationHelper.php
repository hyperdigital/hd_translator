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
                        if ($lang == 'en' || $lang == 'default') {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']]) || !in_array($fileanmePath, $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']][] = $fileanmePath;
                            }
                        } else {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']]) || !in_array($fileanmePath, $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']][] = $fileanmePath;
                            }
                        }
                    }
                }
            }
        }
    }
}

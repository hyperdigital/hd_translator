<?php
namespace Hyperdigital\HdTranslator\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class TranslationHelper
{
    public static function getStoragePath()
    {
        $storage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'storagePath');

        if (!empty($storage)){
            $return = Environment::getProjectPath() ;
            if (substr($storage, 1, 0) != '/') {
                $return .= '/';
            }

            $return .= $storage;

            if (substr($return, -1) != '/') {
                $return .= '/';
            }
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
            $currentPage = 0;
            if (!empty($GLOBALS['TSFE']) && !empty($GLOBALS['TSFE'])) {
                $currentPage = (int) $GLOBALS['TSFE']->id;
            } else if ((int)\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('id')) {
                $currentPage = (int)\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('id');
            }

            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'] as $key => $settings) {
                foreach ($settings['languages'] as $lang) {
                    if ($lang == 'en' || $lang == 'default') {
                        $filename = $key.'.xlf';
                    } else {
                        $filename = $lang.'.'.$key.'.xlf';
                    }

                    $fileanme = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($storage . $filename);

                    if (file_exists($fileanme)) {
                        if ($lang == 'en' || $lang == 'default') {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']]) || !in_array($fileanme, $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']][] = $fileanme;
                            }
                        } else {
                            if (empty($GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']]) || !in_array($fileanme, $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']])) {
                                $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']][] = $fileanme;
                            }
                        }
                    }
                }
            }
        }
    }
}

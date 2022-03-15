<?php
namespace Hyperdigital\HdTranslator\Helpers;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class TranslationHelper
{
    public static function getStoragePath()
    {
        $sotrage = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'storagePath');

        if (!empty($sotrage)){
            $return = Environment::getProjectPath() ;
            if (substr($sotrage, 1, 0) != '/') {
                $return .= '/';
            }

            $return .= $sotrage;

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
                            $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$settings['path']][] = $fileanme;
                        } else {
                            $GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride'][$lang][$settings['path']][] = $fileanme;
                        }
                    }
                }
            }
        }
    }
}
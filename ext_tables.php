<?php
defined('TYPO3') or die();
(function () {


    $GLOBALS['TBE_STYLES']['skins']['hd_translator']['stylesheetDirectories'][] = 'EXT:hd_translator/Resources/Public/Css/Backend/';

    \Hyperdigital\HdTranslator\Helpers\TranslationHelper::setupTranslation();

    // TODO: hook list rightbar menu to add direct download -> EXT:sysext/recordlist/Classes/Controller/RecordListController.php:417
    // translatorController::pageContentExportProccessAction(UID of opened page above)
})();

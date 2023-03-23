<?php
defined('TYPO3') or die();
(function () {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'HdTranslator',
        'web',
        'hd_translator_engine',
        'bottom',
        [
            \Hyperdigital\HdTranslator\Controller\Be\TranslatorController::class => 'index, list, detail, database, databaseTableFields, databaseExport, databaseImport, save, syncLocallangs, search, download, import, remove, exportTableRowIndex'
        ],
        [
            'access' => 'group',
            'iconIdentifier' => 'hd_translator_icon',
            'navigationComponentId' => '',
            'inheritNavigationComponentFromMainModule' => false,
            'labels' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_customizer.xlf',
        ]
    );

    $GLOBALS['TBE_STYLES']['skins']['hd_translator']['stylesheetDirectories'][] = 'EXT:hd_translator/Resources/Public/Css/Backend/';

    \Hyperdigital\HdTranslator\Helpers\TranslationHelper::setupTranslation();
})();

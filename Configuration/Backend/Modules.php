<?php

return [
    'hd_translator_engine' => [
        // TYPO3 14 replaced the "web" main module with "content", and "web_info" no longer exists.
        // The explicit "path" below is kept, so all generated links stay valid.
        'parent' => 'content',
        'position' => ['after' => 'records'],
        'access' => 'user',
        'iconIdentifier' => 'hd_translator_icon',
        'navigationComponentId' => '',
        'inheritNavigationComponentFromMainModule' => false,
        'labels' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_customizer.xlf',
        'extensionName' => 'HdTranslator',
        'path' => '/module/web/HdTranslatorHdTranslatorEngine',
        'controllerActions' => [
            \Hyperdigital\HdTranslator\Controller\Be\TranslatorController::class => [
                'index',
                'list',
                'detail',
                'chooseBackupOrNew',
                'revertBackup',
                'database',
                'databaseTableFields',
                'databaseExport',
                'databaseImport',
                'save',
                'syncLocallangs',
                'search',
                'download',
                'import',
                'remove',
                'exportTableRowIndex',
                'exportTableRowExport',
                'pageContentExport',
                'pageContentExportProccess',
                'databaseImportIndex',
                'databaseImportAction',
                'deeplTranslationsList',
                'deeplSyncLanguages',
                'deeplTranslationLanguage',
                'deeplShowTranslationsOfOriginal',
                'deeplOriginalSources',
                'deeplRemoveAllStrings'
            ]
        ],
    ],
];

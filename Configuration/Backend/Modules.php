<?php

return [
    'hd_translator_engine' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
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

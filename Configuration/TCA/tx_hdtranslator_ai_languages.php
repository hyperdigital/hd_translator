<?php
return [
    'ctrl' => [
        'title' => 'AI available languages',
        'label' => 'name',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'hideTable' => true,
    ],
    'interface' => [
        'showRecordFieldList' => 'sys_language_uid, l10n_parent, l10n_diffsource, hidden, term, starttime, endtime',
    ],
    'types' => [
        '1' => ['showitem' => 'language, name'],
    ],
    'columns' => [
        'language' => [
            'label' => 'Language Code',
            'config' => [
                'type' => 'input',
                'readonly' => true
            ],
        ],
        'name' => [
            'label' => 'Language name',
            'config' => [
                'type' => 'input',
                'readonly' => true
            ],
        ],
    ],
];

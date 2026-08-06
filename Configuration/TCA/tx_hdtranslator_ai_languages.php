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
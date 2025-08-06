<?php
return [
    'ctrl' => [
        'title' => 'AI translations',
        'label' => 'translation',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'hideTable' => true,
    ],
    'types' => [
        '1' => ['showitem' => 'translation, target_language, , original_source, original_translation'],
    ],
    'columns' => [
        'target_language' => [
            'label' => 'Target language',
            'config' => [
                'type' => 'input',
                'eval' => 'trim,required',
            ],
        ],
        'original_source' => [
            'label' => 'Original source',
            'config' => [
                'type' => 'text',
                'readOnly' => true
            ],
        ],
        'original_translation' => [
            'label' => 'Original translation provided by AI',
            'config' => [
                'type' => 'text',
                'readOnly' => true
            ],
        ],
        'translation' => [
            'label' => 'Custom translation',
            'config' => [
                'type' => 'text',
            ],
        ],
    ],
];
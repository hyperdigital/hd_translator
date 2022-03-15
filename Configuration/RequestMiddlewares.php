<?php
return [
    'frontend' => [
        'hd_translator' => [
            'target' => \Hyperdigital\HdTranslator\Middleware\UpdateTranslationSourceMiddleware::class,
//            'before' => [
//                'setupTranslations',
//            ],
        ],
    ],
];
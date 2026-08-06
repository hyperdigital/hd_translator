<?php

$EM_CONF[$_EXTKEY] = array(
    'title' => 'Translator',
    'description' => 'Translation of static strings from locallang.xlf files and export/import of database related content',
    'category' => 'fe',
    'author' => 'Martin Pribyl',
    'author_email' => 'developer@hyperdigital.de',
    'author_company' => 'hyperdigital.de and coma.de',
    'shy' => '',
    'priority' => '',
    'module' => '',
    'state' => 'stable',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'modify_tables' => '',
    'clearCacheOnLoad' => 0,
    'lockType' => '',
    'version' => '3.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
            'php' => '8.2.0-8.99.99'
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
);

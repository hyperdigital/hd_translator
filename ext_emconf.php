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
    'state' => 'beta',
    'internal' => '',
    'uploadfolder' => '0',
    'createDirs' => '',
    'modify_tables' => '',
    'clearCacheOnLoad' => 0,
    'lockType' => '',
    'version' => '1.2.1',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-12.99.99',
            'php' => '8.0.0-8.99.99'
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
);

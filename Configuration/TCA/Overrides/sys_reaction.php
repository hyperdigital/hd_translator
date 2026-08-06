<?php

defined('TYPO3') or die();

// EXT:reactions is optional, without it there is no sys_reaction to extend
if (!\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('reactions')) {
    return;
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns(
    'sys_reaction',
    [
        'hdtranslator_language' => [
            'label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_language',
            'description' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_language.description',
            'displayCond' => 'FIELD:reaction_type:=:hd-translator-import',
            'config' => [
                'type' => 'language',
            ],
        ],
        'hdtranslator_site' => [
            'label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_site',
            'description' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_site.description',
            'displayCond' => 'FIELD:reaction_type:=:hd-translator-import',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \Hyperdigital\HdTranslator\Tca\ReactionItemsProvider::class . '->getSites',
                'items' => [
                    ['label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_site.all', 'value' => ''],
                ],
                'default' => '',
            ],
        ],
        'hdtranslator_qa_mode' => [
            'label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_qa_mode',
            'description' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_qa_mode.description',
            'displayCond' => 'FIELD:reaction_type:=:hd-translator-import',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_qa_mode.report', 'value' => 'report'],
                    ['label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_qa_mode.skip', 'value' => 'skip'],
                    ['label' => 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.hdtranslator_qa_mode.reject', 'value' => 'reject'],
                ],
                'default' => 'report',
            ],
        ],
    ]
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_reaction',
    'hdtranslator_language, hdtranslator_site, hdtranslator_qa_mode',
    '',
    'after:secret'
);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTcaSelectItem(
    'sys_reaction',
    'reaction_type',
    [
        'label' => \Hyperdigital\HdTranslator\Reaction\ImportTranslationReaction::getDescription(),
        'value' => \Hyperdigital\HdTranslator\Reaction\ImportTranslationReaction::getType(),
        'icon' => \Hyperdigital\HdTranslator\Reaction\ImportTranslationReaction::getIconIdentifier(),
    ]
);

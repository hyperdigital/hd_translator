<?php
defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'hd_translator',
    'Configuration/TypoScript/AiDeepl',
    'Translator: AI deepl basic functionality'
);

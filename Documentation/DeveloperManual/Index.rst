.. ==================================================
.. FOR YOUR INFORMATION
.. --------------------------------------------------
.. -*- coding: utf-8 -*- with BOM.

.. _introduction:

Developer Manual
============

How to set translatable extension
---------------------------------


Enable new language which is not supported by TYPO3
---------------------------------------------------
Add this code to LocalConfiguration.php or AdditionalConfiguration.php. It has to be there before all exts are loaded.
`
$GLOBALS['TYPO3_CONF_VARS']['SYS']['localization']['locales']['user']['us'] = 'English US';
`
Iportant parts are 'us' as language key (used in translation files) and 'English US' as language name.

It's also possible to add dependency by adding this code (if AT language is missing then use DE):
`
$GLOBALS['TYPO3_CONF_VARS']['SYS']['localization']['locales']['dependencies']['at'] = ['de'];
`

Database exports
----------------
Default list of exported fields from the database is in the same location like show fields and it has also the same "type" logic. If this settings is empty, all accessible not TYPO3 core fields would be shown
`
$GLOBALS['TCA'][$table]['types'][1]['translator_export'] = 'title,subtitle,another_field'
`

Sinlge row export is accessible over List module in the control section (where are icons for hiding etc.)



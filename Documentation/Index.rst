:navigation-title: Translator

..  _start:

==========
Translator
==========

:Extension key:
    hd_translator

:Package name:
    hyperdigital/hd_translator

:Version:
    |release|

:Language:
    en

:License:
    This document is published under the
    `Creative Commons BY 4.0 <https://creativecommons.org/licenses/by/4.0/>`__
    license.

----

TYPO3 extension for handling translations. It lets editors edit static strings from
XLF files (usually placed in :file:`EXT:.../Resources/Private/Language`) and export
database records, have them translated in the XLIFF format by a translation tool or
agency, and import them back into TYPO3.

----

..  _features:

Features
========

Static string translations
    Edit the labels of any :file:`locallang.xlf` file from the backend. The extension
    never writes into :file:`EXT:` directories - it stores override files in a
    configurable storage path and registers them through
    :php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['locallangXMLOverride']`.

Database export and import
    Export pages, content elements and any other TCA table (including inline records,
    file references and FlexForm fields) into XLIFF, and import the translated files
    back as TYPO3 translation records.

DeepL AI translations
    Translate frontend output on the fly through DeepL, with every string cached in the
    database so the API limits are not hit repeatedly.

..  _installation:

Installation
============

..  code-block:: bash

    composer require hyperdigital/hd_translator

After installation a new submodule appears below the :guilabel:`Web` module. Before
using it, open :guilabel:`Settings > Extension Configuration > hd_translator` and set
:guilabel:`Storage path (from the root of the project)`. The path is relative to the
project root, the directory holding your :file:`composer.json`.

..  _configuration:

Extension configuration
=======================

..  confval:: storagePath

    :type: string

    Directory the generated XLF override files are written to, relative to the project
    root. Required - without it the module only shows a setup hint.

..  confval:: allLocallangs

    :type: boolean

    Enables the :guilabel:`Synchronize all files` button, which scans every installed
    extension for :file:`locallang.xlf` files and generates the configuration for them.

..  confval:: useCategorization

    :type: boolean

    Groups the translation keys by their dotted prefix in the editing view.

..  confval:: deeplApiKey

    :type: string

    DeepL API key. When empty, the :guilabel:`AI Translations` tab stays hidden and the
    frontend endpoints answer with HTTP 503.

..  _registering-files:

Registering a locallang file
============================

..  code-block:: php
    :caption: EXT:my_extension/ext_localconf.php

    $GLOBALS['TYPO3_CONF_VARS']['translator']['unique_key'] = [
        'label' => 'My Cool Extension - Base',
        'path' => 'EXT:cool_extension/Resources/Private/Language/locallang.xlf',
        'category' => 'Cool Extension',
        'languages' => ['en', 'de', 'cs'],
    ];

..  _tca-options:

TCA options for database export
===============================

..  confval:: translator_export

    :type: string
    :Path: :php:`$GLOBALS['TCA'][$table]['types'][$type]['translator_export']`

    Comma separated list of fields to export. When unset, all non-core fields are
    exported.

..  confval:: translator_export_column

    :type: array
    :Path: :php:`$GLOBALS['TCA'][$table]['types'][$type]['translator_export_column']`

    Limits the exported FlexForm fields of a column, for example
    :php:`['pi_flexform' => 'settings.text, settings.header']`.

..  confval:: translator_export_column_notes

    :type: array
    :Path: :php:`$GLOBALS['TCA'][$table]['types'][$type]['translator_export_column_notes']`

    Adds a note for translators to a single FlexForm field.

..  confval:: translator_note

    :type: string
    :Path: :php:`$GLOBALS['TCA'][$table]['columns'][$field]['config']['translator_note']`

    Adds a note for translators to a regular field.

..  confval:: translator_import_ignore

    :type: string
    :Path: :php:`$GLOBALS['TCA'][$table]['types'][$type]['translator_import_ignore']`

    Fields that must never be overwritten on re-import, for example :php:`'slug,url'`.

..  _multi-site:

Multiple sites
==============

The module has no page tree, so TYPO3 does not supply the :php:`id` parameter by itself.
The extension therefore resolves the page from the record or page the current action
works on and passes :php:`id` along in its own links. This is what allows the source and
target language dropdowns to show the languages of the correct site - important as soon
as two sites use different default languages.

..  _deepl:

DeepL translations
==================

Add your API key, open the :guilabel:`AI Translations` tab and click
:guilabel:`Synchronize available languages`. Include the static TypoScript template
:guilabel:`Translator: AI deepl basic functionality` to use the frontend helpers:

..  code-block:: javascript

    hdtranslator_translateWholePage('de');
    hdtranslator_translateText('Translate me this content', 'de');
    hdtranslator_fetchSupportedLanguages();

Elements marked with :html:`class="notranslate"`, :html:`data-notranslate` or
:html:`translate="no"` are skipped.

The frontend endpoints are public. They only accept target languages that were
synchronized from DeepL, and they reject requests with more than 50 strings, single
strings longer than 5000 characters, or a total payload above 50000 characters.

Or call the service directly:

..  code-block:: php

    $deeplApi = GeneralUtility::makeInstance(DeeplApiService::class);
    $languages = $deeplApi->getAvailableLanguages(true);
    $translations = $deeplApi->translateTexts(['Translate me this content'], 'de');

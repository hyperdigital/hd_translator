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

..  confval:: aiRequestStatistics

    :type: boolean
    :Default: 1

    Counts how many distinct visitors ask for each language, which is what the
    :guilabel:`Demand` screen shows. Switch it off and nothing is written at all.

..  confval:: aiRequestStatisticsRetention

    :type: integer
    :Default: 365

    Days a counting row is kept. Older rows are dropped once a day.

..  confval:: requestsPerMinute

    :type: integer
    :Default: 60

    How many translate requests one address may send per minute. Above it the endpoint
    answers HTTP 429 with a :html:`Retry-After` header. The endpoint has no login,
    because the frontend has to reach it, and every unseen string is paid for at DeepL.

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

..  _source-language:

Exporting a language other than the default one
===============================================

The export forms offer a :guilabel:`Source TYPO3 Language` select. It decides which language
version is written into the XLF file, so an existing translation can be handed out for review or
used as the base of a further language.

The keys of the exported file always use the uid of the default language record, which is what
lets the import map the file back onto the correct records no matter which language was exported.
The values, including inline children and file references, come from the selected source language.
If the selected language has no own inline children, the ones of the default language are exported
instead, so nothing is silently lost.

..  _existing-translation:

Prefilling the target from an existing translation
==================================================

The export forms also offer :guilabel:`Prefill target from existing translation (optional)`. It is
switched off by default, because the usual case is content that has not been translated yet.

When a language is selected there and a translation of the exported record already exists, its
values are written into the ``<target>`` of the XLF file, while ``<source>`` keeps the source
language. Translators then see what is already there and can correct it instead of starting over.
Fields without an existing translation, and records that have no translation in that language at
all, keep the previous behaviour and repeat the source value in ``<target>``.

..  note::
    The prefill matches fields by the same keys the export uses, so it relies on translated inline
    records pointing at their default language original. Records translated in free mode, which do
    not keep that pointer, are exported without a prefill rather than with a wrong one.

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
    hdtranslator_recordBannerShown('de');
    hdtranslator_recordBannerAccepted('de');

Elements marked with :html:`class="notranslate"`, :html:`data-notranslate` or
:html:`translate="no"` are skipped.

Which languages people ask for
------------------------------

The :guilabel:`Demand` button on the :guilabel:`AI Translations` screen opens a screen
that answers which languages visitors actually request, so the one worth building as
real pages can be picked out. A chart draws the unique visitors per day over the last
7, 30, 90 or 365 days. The table below it carries the same numbers, sorted by any
column, together with a sparkline of the range, how many translations exist and whether
the language is already a real language of a site.

The chart answers three questions, one per mode:

:guilabel:`Lines`
    One line per language - which language is growing.

:guilabel:`Total`
    The languages stacked - whether the demand is growing at all. The lines alone never
    said that, and it is the question behind "is this worth maintaining".

:guilabel:`Share`
    The same stack to a hundred percent - how the demand is divided, whatever the traffic
    did.

:guilabel:`7 day average` replaces each day by the mean of the week around it. Over ninety
days and more the daily noise is louder than the trend the reader came for; the raw series
is one click away.

Dragging across the plot zooms into that range and :guilabel:`Reset zoom` appears; the
range buttons reload the screen, everything else is immediate. Hovering reads every
series at that day at once, marks each one with a dot and adds the total. Pointing at a
language - in the legend or in a table row - lifts its line and dims the rest, which is
the only way to follow one of six lines that cross. Clicking a legend key still hides a
language.

Without a mouse: the chart takes focus, the arrow keys move the readout, :kbd:`Home` and
:kbd:`End` jump to the ends and :kbd:`Esc` clears it. Every value in the chart is in the
table below it, which is what the chart's description points screen readers at, and
:guilabel:`Copy the table as CSV` takes the table as it is read - sorting included,
sparklines left out.

What is stored is one row per language, per day, per visitor - no counters and no
timestamps. The visitor is an HMAC of the address and the user agent, keyed with the
installation's encryption key **and the day**, so the same person hashes differently
tomorrow and yesterday's rows can no longer be tied to anybody. No address and no user
agent is written anywhere. See :confval:`aiRequestStatistics` to switch it off and
:confval:`aiRequestStatisticsRetention` for how long rows are kept.

Beside the chart the screen answers four more questions, three of them without storing
anything that was not already there:

**Which days the asking happens on.** Read out of the day each row carries, so a weekend
audience and an office one are told apart at a glance.

**Whether the demand is concentrated or spread.** The share held by the busiest three
languages, and the sum of the squared shares as an index - 1 is everybody asking for the
same language, 1/n is all of them asked for equally. This is the difference between
building one mutation and building several.

**What each language has cost.** DeepL charges by the character, and the source texts are
already in the translation cache, so the :guilabel:`Characters translated` column is the
closest thing to a bill the installation can answer without writing a number anywhere.

**Where the asking happens.** The pages a translation was requested on, busiest first.
This one needs a table, :sql:`tx_hdtranslator_ai_request_page`, and it is bounded on both
ends: a language-day accepts at most fifty distinct pages, a day that has closed keeps the
busiest ten, and pages are dropped after ninety days even where the counting rows live a
year. The page comes from the referer the browser already sends - nothing changes in the
frontend or in a project using it - and only the path is kept, never the query, so what a
visitor typed into a search never reaches the table. It counts requests, not people: a page
is not a visitor, and pairing the two would multiply the rows by the size of the site.

..  _overlay:

How a language got the visitors it got
--------------------------------------

:guilabel:`How it got them` splits one language in the daily chart. The demand line does not
move; it gains a boundary inside it and a second half below the axis:

**Filled, inside the line**: the visitors the banner brought - the people who asked for a
translation *and* agreed to the banner. This can never overflow the line, because it is a part
of it: the demand rows and the banner rows carry the **same** day-salted visitor hash for the
same person, so this is a set operation over one key rather than two numbers being compared.

**The rest of the line**: everybody else who asked - a language switcher, a remembered choice,
a direct link, or somebody who ignored the banner and translated anyway.

**Below the axis**: the people the banner was shown to who never asked for a translation that
day. They are not in the line at all, which is exactly why they hang below it instead of being
stacked on top. Somebody who agreed and then left before the translation started counts here,
which is the honest reading of what happened.

..  note::
    How many people were offered a translation says nothing about how many asked for one -
    either number can be the larger, and both happen on real sites. That is why the two are on
    opposite sides of the axis and only their overlap is drawn inside the line. Both halves share
    one scale, so their heights can be compared directly.

A day the banner reported nothing about is drawn without any split at all, and the readout says
so. A day with no banner rows is not a day on which the banner brought nobody, and drawing the
two the same way would turn "nobody measured" into "the banner is useless".

The split talks about one language at a time - six languages with three bands each would be a
wall of colour. Pointing at a language, in the legend or in a table row, switches to it;
switching the view on without pointing at anything picks the language with the most to say. It
is drawn in the :guilabel:`Lines` mode only, because the fill carries a different meaning in the
stacked ones.

..  _sources:

Where the asking comes from
---------------------------

A language the site already has reads as demand and may be anything but. Somebody on the
english page who asks for german wants a german page; a banner offering german on a page that
is already german is a defect that inflates the same number. The counting rows know what was
asked for and never where from, so the two used to be one figure.

The language version a request came from is now resolved from the referer the browser already
sends - nothing changes in the frontend and nothing in a project using it - by matching it
against the bases of the site configuration. The longest matching base wins, so ``/de-de/``
beats the root language of the same site. The host is matched first and dropped on a second
pass, because a staging or ddev copy answers on a host the site configuration does not name and
would otherwise attribute nothing at all. Only the resolved language is stored, never the url.

The :guilabel:`Demand` screen lists, per language that was asked for, which language versions
the asking happened on, and calls out two cases:

**The page was already in this language.** Nobody asks for that on purpose, so the figure is a
defect report about the banner rather than a measurement. It also gets a tile of its own beside
the other insights, in a warning colour, so it is not read as demand.

**It was asked for although it exists as real pages.** Those visitors could have been sent to
the page instead of to a translation, which is a question about navigation or a missing
redirect, not about building a language.

What is stored is :sql:`tx_hdtranslator_ai_request_source` - one row per language pair per day,
no visitor, so it counts requests and is bounded by construction: a day holds at most as many
rows as there are language pairs, whatever the traffic. The rows follow
:confval:`aiRequestStatisticsRetention` like the counting rows they belong to.

..  _banner:

Who was offered a translation, and who agreed
---------------------------------------------

The number of translate requests says how many people used the translation, never how many
were asked. Both are needed to answer whether the people who are offered a translation
actually want it - and the banner that does the asking belongs to the site package, not to
this extension, so it reports the two events itself:

..  code-block:: javascript

    hdtranslator_recordBannerShown('de');      // every time the banner becomes visible
    hdtranslator_recordBannerAccepted('de');   // when the visitor agrees

Both helpers come with the static TypoScript template, take the target language and return a
promise nobody has to await. They send one short request each and use ``keepalive``, so a click
that navigates away still delivers.

:js:`hdtranslator_recordBannerShown()` belongs on **every** display, not once per visit. Two
numbers are kept and they answer different questions: how often the banner was shown, and how
many visitors that was. Fifty displays can be fifty visitors or one visitor on fifty pages, and
only both together say which - the backend deduplicates the visitor behind a display by itself.

The :guilabel:`Demand` screen then shows the three of them as a funnel - how often the banner
was shown, how many visitors that was, how many agreed - and repeats them per language in the
table as :guilabel:`Shown` (displays), :guilabel:`Offered` (visitors) and :guilabel:`Agreed`
(visitors) with the rate between the last two. A language the banner offered and nobody took appears in
the table even though it has no requests at all, which is the case worth seeing. Until something
reports, the screen says so rather than showing an empty column.

..  important::
    Without these two calls the only number there is are translate requests - and a banner that
    translates its own text through :js:`hdtranslator_translateText()` turns being shown into a
    request as well. Being offered and agreeing are then the same row, and nothing can tell them
    apart afterwards.

What is stored are two tables, both following :confval:`aiRequestStatisticsRetention` and
neither folded into months - the question is about the banner as it stands, and a year-old rate
is about a banner that has since been reworded:

:sql:`tx_hdtranslator_ai_banner`
    One row per language, day, visitor and event, with the same day-salted visitor hash as the
    counting rows. Somebody who meets the banner on five pages is one row, which is what makes
    the ratio of the two events a rate.

:sql:`tx_hdtranslator_ai_banner_count`
    One row per language, day and event, holding how often it happened. No visitor is involved,
    so this counts displays rather than people, and the table is bounded by construction: a
    language-day has one row per event whatever the traffic.

A returning visitor whose page is translated entirely out of the browser cache never reaches
the translate endpoint, so the demand counter used to miss exactly the people who use the
translation most. :js:`hdtranslator_translateWholePage()` now reports that case through the same
endpoint, which writes the row a translate request would have written.

..  _demand-history:

History after the detail is gone
--------------------------------

Below the demand chart, a second chart stacks the **visitor-days per month** of every
language. It had been computed and handed to the screen since 2.6.0 without any template
ever drawing it - one query per visit that nobody saw. Hovering or focusing a month names
every language in it.

When the retention drops the daily rows, the prune first folds them into
:sql:`tx_hdtranslator_ai_request_month` - one row per language per month, so a decade of
history costs less than a day of detail. It adds rather than replaces, which keeps it
correct when the retention boundary falls inside a month, and running twice changes
nothing because the rows are gone by then.

What a month holds is **visitor-days**, not people: somebody who asked on three days counts
three times. The number of distinct people in a month cannot be recovered once the daily
rows are gone, because the visitor hash is re-salted every day on purpose, and the column
does not pretend otherwise.

The frontend endpoints are public. They only accept target languages that were
synchronized from DeepL, and they reject requests with more than 500 strings, single
strings longer than 5000 characters, or a total payload above 50000 characters. 500 is
not DeepL's own limit: the service splits what it has to forward by request size, so a
page that is already cached is resolved in one request rather than one per fifty
strings. Requests above :confval:`requestsPerMinute` are answered with HTTP 429. The banner endpoint
accepts the same languages and shares the same limit; it writes a counting row and nothing
else.

Or call the service directly:

..  code-block:: php

    $deeplApi = GeneralUtility::makeInstance(DeeplApiService::class);
    $languages = $deeplApi->getAvailableLanguages(true);
    $translations = $deeplApi->translateTexts(['Translate me this content'], 'de');

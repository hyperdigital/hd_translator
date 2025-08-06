# Translator
TYPO3 extension for handling translations. It allows editors to edit static strings from XLF files (usually placed in *EXT:/Resources/Private/Languages*) or to export database entries, edit them over translation tool/agency in the xlf format and then import it again back to TYPO3.
## Initialization

Upon installation, a new submodule will be added under the WEB module. Before utilizing
the extension's features, it is essential to specify the location where new translations should be stored.
Follow these steps:

1. Navigate to the Extension Configuration section.
2. Look for the "Storage path (from the root of the project)" option within the HD Translator settings.
When inserting a relative path in this field, consider that starting point is the project root,
where your project's `composer.json` is stored.

## Static Strings Translations
### Previews
#### All locallang files categories
![All locallang filed](Documentation/Images/all_locallang_files.PNG "All locallang filed")

#### All locallangs for category
![All locallang for specific category](Documentation/Images/category.PNG "All locallang for specific category")

#### Example of german translation for one locallang
![Example of german translation](Documentation/Images/detail.PNG "Example of german translation")

### Specific locallang files
A key feature of this extension lies in its ability to work seamlessly with `locallang.xlf` files. To enable the handling of specific `locallang.xlf` files, follow these steps:


1. Open the `ext_localconf.php` file in your TYPO3 extension.

2. Add the following configuration to register the desired `locallang.xlf` file (the unique_key must be always unique):

    ```php
    $GLOBALS['TYPO3_CONF_VARS']['translator']['unique_key'] = [
        'label' => 'My Cool Extension - Base',
        'path' => 'EXT:cool_extension/Resources/Private/Language/locallang.xlf',
        'category' => 'Cool Extension',
        'languages' => [
            'en',
            'de',
            'cs'
        ]
    ];
    ```
    - `label`: Provide a descriptive label for the set of translations.
    - `path`: Specify the path to the target `locallang.xlf` file within your extension.
    - `category`: Categorize the translations under a specific category.
    - `languages`: Define the supported languages for the translations.

Ensure that the provided path is correct and corresponds to the actual location of your `locallang.xlf` file. You can extend the 'languages' array to include additional language codes as required.

### Synchronizing All locallang.xlf Files

The system has the capability to retrieve all accessible `locallang.xlf` files across all extensions, making translation management more efficient. Follow these steps to synchronize the files:

1. In the extension configuration, locate and check the checkbox labeled "All locallang.xlf files."

2. Navigate to the Translator module within the TYPO3 backend.

3. Find the "Synchronize all files" button and click on it.

This action generates settings for all the `locallang.xlf` files, ensuring that the system is aware of and can handle translations from every extension. If you add a new translation file or introduce a new extension, it's essential to click the "Synchronize all files" button again. This process updates the system settings accordingly.

By utilizing this feature, you streamline the management of translations across your TYPO3 project, ensuring that the Translator module is aware of all relevant translation files and extensions.

### Correct Language Configuration

To define the correct language for your TYPO3 site, you can specify it within the site configuration file located at `config/sites/xxx/config.yaml`. Here's an example configuration:

```yaml
languages:
  -
    ...
    typo3Language: de
    ...
```

### Enable New Language not Supported by TYPO3

To enable a new language not supported by TYPO3, add the following code to either `LocalConfiguration.php` or `AdditionalConfiguration.php`. Ensure that this code appears before all extensions are loaded:

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['localization']['locales']['user']['us'] = 'English US';
```

Important Components:
- 'us': This serves as the language key used in translation files.
- 'English US': Represents the name of the language.

## Database Export

The extension provides a flexible mechanism for exporting fields from the database, allowing you to tailor the exported data based on your needs.

### Default Export Settings

The default list of exported fields from the database is located in the same section as the "Show Fields" configuration. It follows the same "type" logic. If this setting is empty, all accessible non-TYPO3 core fields will be included in the export.

```php
$GLOBALS['TCA'][$table]['types'][1]['translator_export'] = 'title, subtitle, another_field';
```
#### Different Settings for Different Table Types

In many cases, you may find it necessary to have distinct settings for various content elements. For instance, you might want specific export configurations for a Content Element of type 'Header' as opposed to a 'Text' element. To achieve this level of granularity, utilize the type-specific settings. Here's an example for 'Header' and 'Text' elements within the `tt_content` table:

##### Header Content Element
```php
$GLOBALS['TCA']['tt_content']['types']['header']['translator_export'] = 'header, subheader';
```
##### Text Content Element
```php
$GLOBALS['TCA']['tt_content']['types']['text']['translator_export'] = 'header, subheader, bodytext';
```

#### Exporting Flexform Fields

When the Flexform is included in the export, it retrieves all fields by default. However, you can limit the exported fields similar to the entire table. Use the following configuration:

```php
$GLOBALS['TCA'][$table]['types'][1]['translator_export_column']['pi_flexform'] = 'settings.text, settings.header';
```

#### Custom Notes for fields

If the fields requires some special note, it's possible to add into notes by different options:

##### Pure TCA config
```php
$GLOBALS['TCA']['tt_content']['columns']['header']['config']['translator_note'] = 'Here is a note for translators';
```

##### Flexfrom config
```php
$GLOBALS['TCA'][$table]['types'][1]['translator_export_column_notes']['pi_flexform']['settings.text'] = 'Here is a note for translators';
```

##### Disable updates of fields
This feature can be helpful when you don't want to override exisitng page slugs. When you are importing database entries,
the insert to the database will be as usual, but when you want to import the same table again, the fields from the list would be ignored.
```php
$GLOBALS['TCA']['pages']['types'][1]['translator_import_ignore'] = 'slug,url';
```


### Displaying Export Buttons

Export buttons will appear next to the save or edit buttons when editing a table entry or within the Page module. It's also a part of context menues.

Customize the export settings according to your specific requirements to ensure that only the necessary data is included in the exported file.
## DeepL AI Translation

This extension enables AI-powered translations using DeepL **in the frontend only**. To get started, complete the setup steps below:

1. **Add your DeepL API key**  
   Go to your DeepL account → [API Keys](https://www.deepl.com/de/your-account/keys) and copy your key into the extension’s configuration.
2. **Enable AI Translations**  
   In the TYPO3 backend, open the **Translator** module and switch the header tab to **AI Translations**.
3. **Synchronize languages**  
   Click **Synchronize available languages**. This will fetch all supported languages and make them available for translation.

Once that’s done, you’ll see a list of languages along with any existing translations.

---

### Using Translations in the Frontend

Under the hood:

- Your page content is parsed by JavaScript into individual strings.
- Each string is sent to a data processor.
- The processor checks if a translation already exists in the database:
  - **If yes**, it returns the cached translation.
  - **If no**, it queries DeepL and stores the result for future use.

This caching strategy prevents you from hitting DeepL’s API limits too quickly.

To enable on-the-fly translation, include the provided TypoScript static file in your site template. Then you can call these JS functions:

```js
// Translate the entire page into German (code “de”)
hdtranslator_translateWholePage('de');
// → Asynchronously scans the DOM in segments and replaces text in order.

// Translate a single string
hdtranslator_translateText('Translate me this content', 'de');
  .then(translation => console.log(translation))
  .catch(err => console.error('Failed to load translation:', err));

// Fetch supported DeepL languages
hdtranslator_fetchSupportedLanguages()
  .then(languages => console.log(languages))
  .catch(err => console.error('Failed to load languages:', err));
```

For the function `hdtranslator_translateWholePage` you can ommit specific strings by:
- class="notranslate"
- data-notranslate
- translate="no"


### PHP API Usage
You can also call DeepL directly in PHP via the `DeeplApiService`:
```php
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hyperdigital\HdTranslator\Services\DeeplApiService;

// 1) Get all available languages
/** @var DeeplApiService $deeplApi */
$deeplApi = GeneralUtility::makeInstance(DeeplApiService::class);
$languages = $deeplApi->getAvailableLanguages(true);

// 2) Translate one or more texts
$texts = ['Translate me this content'];
$translations = $deeplApi->translateTexts($texts, 'de');
```

### Editing Existing Translations
1. In the Translator module, open the AI Translations tab.
2. Select the target language from the dropdown.
3. Locate the string you want to update:
    a) Click the translated text to open a TYPO3 editing form.
    b) Or click the original (source) text to view all translations across every language.
4. Edit and save—your changes will appear on the frontend immediately.
<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use Symfony\Component\Translation\Dumper\CsvFileDumper;
use Symfony\Component\Translation\Dumper\FileDumper;
use Symfony\Component\Translation\Dumper\JsonFileDumper;
use Symfony\Component\Translation\Dumper\PoFileDumper;
use Symfony\Component\Translation\Dumper\YamlFileDumper;
use Symfony\Component\Translation\Loader\CsvFileLoader;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Loader\MoFileLoader;
use Symfony\Component\Translation\Loader\PoFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Exchange formats other than XLIFF, through the loaders and dumpers of the Symfony Translation
 * component that TYPO3 already ships.
 *
 * Translators do not all work with XLIFF. Poedit and most open source workflows use gettext, and a
 * spreadsheet is still the most common way a small client returns a handful of strings. Those
 * formats carry the translation only: no source, no field label, no maximum length and no state.
 * A file loaded through them therefore has an empty source, which the quality check takes into
 * account, and they are a one way street for content that was exported as XLIFF before.
 */
class TranslationFormatService
{
    public const FORMAT_XLF_12 = 'xlf12';
    public const FORMAT_XLF_20 = 'xlf20';
    public const FORMAT_PO = 'po';
    public const FORMAT_JSON = 'json';
    public const FORMAT_YAML = 'yaml';
    public const FORMAT_CSV = 'csv';

    protected const DOMAIN = 'messages';

    /**
     * @var array<string, array{extension: string, contentType: string, label: string}>
     */
    protected const FORMATS = [
        self::FORMAT_XLF_12 => ['extension' => 'xlf', 'contentType' => 'application/xliff+xml', 'label' => 'XLIFF 1.2'],
        self::FORMAT_XLF_20 => ['extension' => 'xlf', 'contentType' => 'application/xliff+xml', 'label' => 'XLIFF 2.0'],
        self::FORMAT_PO => ['extension' => 'po', 'contentType' => 'text/x-gettext-translation', 'label' => 'Gettext PO'],
        self::FORMAT_JSON => ['extension' => 'json', 'contentType' => 'application/json', 'label' => 'JSON'],
        self::FORMAT_YAML => ['extension' => 'yaml', 'contentType' => 'application/yaml', 'label' => 'YAML'],
        self::FORMAT_CSV => ['extension' => 'csv', 'contentType' => 'text/csv', 'label' => 'CSV'],
    ];

    /**
     * File extensions that are read through a Symfony loader. XLIFF stays with XlfService, which
     * also reads the notes, the maximum length and the state that this extension writes into it.
     *
     * @var array<string, class-string<LoaderInterface>>
     */
    protected const LOADERS = [
        'po' => PoFileLoader::class,
        'mo' => MoFileLoader::class,
        'json' => JsonFileLoader::class,
        'yaml' => YamlFileLoader::class,
        'yml' => YamlFileLoader::class,
        'csv' => CsvFileLoader::class,
    ];

    /**
     * @var array<string, class-string<FileDumper>>
     */
    protected const DUMPERS = [
        self::FORMAT_PO => PoFileDumper::class,
        self::FORMAT_JSON => JsonFileDumper::class,
        self::FORMAT_YAML => YamlFileDumper::class,
        self::FORMAT_CSV => CsvFileDumper::class,
    ];

    public function __construct(protected readonly XlfService $xlfService) {}

    /**
     * @return array<string, string> format identifier to the label shown in the export forms
     */
    public function getExportFormats(): array
    {
        $formats = [];
        foreach (self::FORMATS as $format => $definition) {
            $formats[$format] = $definition['label'];
        }

        return $formats;
    }

    /**
     * @return array<int, string> file extensions the import accepts, without the dot
     */
    public function getImportExtensions(): array
    {
        return array_merge(['xlf'], array_keys(self::LOADERS));
    }

    public function isSupportedExportFormat(string $format): bool
    {
        return isset(self::FORMATS[$format]);
    }

    public function canLoad(string $extension): bool
    {
        return $extension === 'xlf' || isset(self::LOADERS[strtolower($extension)]);
    }

    public function getFileExtension(string $format): string
    {
        return self::FORMATS[$format]['extension'] ?? 'xlf';
    }

    public function getContentType(string $format): string
    {
        return self::FORMATS[$format]['contentType'] ?? 'application/xliff+xml';
    }

    /**
     * Serialises the export data of a record in the requested format.
     *
     * @param array $data the structure the exports build, keyed by translation key
     */
    public function dump(array $data, string $format, string $targetLanguage, string $sourceLanguage = '', string $keyTranslation = ''): string
    {
        if ($format === self::FORMAT_XLF_20) {
            return (string)$this->xlfService->dataToXlf($data, $targetLanguage, $sourceLanguage, $keyTranslation, XlfService::VERSION_20);
        }

        if (!isset(self::DUMPERS[$format])) {
            return (string)$this->xlfService->dataToXlf($data, $targetLanguage, $sourceLanguage, $keyTranslation, XlfService::VERSION_12);
        }

        $messages = [];
        foreach ($data as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            [, $target] = $this->xlfService->resolveSourceAndTarget($value, $targetLanguage);
            $messages[(string)$key] = $target;
        }

        $catalogue = new MessageCatalogue($targetLanguage !== '' ? $targetLanguage : 'en', [self::DOMAIN => $messages]);

        /** @var FileDumper $dumper */
        $dumper = GeneralUtility::makeInstance(self::DUMPERS[$format]);

        return $dumper->formatCatalogue($catalogue, self::DOMAIN);
    }

    /**
     * Reads a file in the shape XlfService::parse() returns, so the quality check and the import
     * do not have to know which format the content came in.
     *
     * The formats behind the Symfony loaders carry no source text, so "source" stays empty and the
     * entries are reported as translated rather than as reviewed.
     *
     * @return array<string, array{source: string, target: string, state: string, approved: bool, maxLength: int|null, notes: array, html: bool}>
     */
    public function load(string $content, string $extension): array
    {
        $extension = strtolower($extension);

        if ($extension === 'xlf' || $extension === 'xliff') {
            return $this->xlfService->parse($content);
        }

        if (!isset(self::LOADERS[$extension])) {
            return [];
        }

        /** @var LoaderInterface $loader */
        $loader = GeneralUtility::makeInstance(self::LOADERS[$extension]);

        // the Symfony loaders read from disk, the uploaded content only exists in memory here
        $temporaryFile = GeneralUtility::tempnam('hd_translator_import_', '.' . $extension);
        $return = [];

        try {
            GeneralUtility::writeFile($temporaryFile, $content);
            $catalogue = $loader->load($temporaryFile, 'en', self::DOMAIN);

            foreach ($catalogue->all(self::DOMAIN) as $key => $target) {
                $return[(string)$key] = [
                    'source' => '',
                    'target' => (string)$target,
                    'state' => XlfService::STATE_TRANSLATED,
                    'approved' => false,
                    'maxLength' => null,
                    'notes' => [],
                    'html' => false,
                ];
            }
        } catch (\Throwable $e) {
            // a file the loader cannot read yields no entries, the import reports that as empty
            $return = [];
        } finally {
            GeneralUtility::unlink_tempfile($temporaryFile);
        }

        return $return;
    }

    /**
     * Flattens parsed entries to the key to value map the import writes.
     *
     * @param array $entries output of load() or XlfService::parse()
     * @return array<string, string>
     */
    public function toImportData(array $entries, string $sourcePath = 'target'): array
    {
        $data = [];
        foreach ($entries as $key => $entry) {
            $value = (string)($entry[$sourcePath] ?? '');
            if (trim($value) === '') {
                continue;
            }
            $data[(string)$key] = $value;
        }

        return $data;
    }
}

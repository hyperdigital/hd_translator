<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Reads and writes the XLIFF files exchanged with translators.
 *
 * Both XLIFF 1.2 and XLIFF 2.x are read, the version is detected from the file. Writing defaults
 * to 1.2, which is what TYPO3 itself ships, and 2.x can be requested for translation systems that
 * expect the newer standard.
 */
class XlfService
{
    public const VERSION_12 = '1.2';
    public const VERSION_20 = '2.0';

    /**
     * XLIFF 2.x segment states. "initial" and "translated" count as not approved,
     * "reviewed" and "final" count as approved, mirroring how TYPO3 core reads them.
     */
    public const STATE_INITIAL = 'initial';
    public const STATE_TRANSLATED = 'translated';
    public const STATE_REVIEWED = 'reviewed';
    public const STATE_FINAL = 'final';

    protected const APPROVED_STATES = [self::STATE_REVIEWED, self::STATE_FINAL];

    protected const XLIFF_20_NAMESPACE = 'urn:oasis:names:tc:xliff:document:2.0';

    /**
     * @param array $data - array of ['default => 'SomeString', 'de' => 'translated string', '_label' => 'LABEL', '_html' => true ,'notes' = [] ]
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param string $keyTranslation
     * @param string $version XLIFF version to write, 1.2 or 2.0
     * @return false|string
     */
    public function dataToXlf(array $data, string $targetLanguage, string $sourceLanguage = '', string $keyTranslation = '', string $version = self::VERSION_12)
    {
        if ($version === self::VERSION_20) {
            return $this->dataToXlf20($data, $targetLanguage, $sourceLanguage, $keyTranslation);
        }

        return $this->dataToXlf12($data, $targetLanguage, $sourceLanguage, $keyTranslation);
    }

    /**
     * @return false|string
     */
    protected function dataToXlf12(array $data, string $targetLanguage, string $sourceLanguage = '', string $keyTranslation = '')
    {
        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;
        $xmlRoot = $domtree->createElement('xliff');
        $xmlRoot->setAttribute('version', self::VERSION_12);

        $file = $domtree->createElement('file');
        if (!empty($sourceLanguage)) {
            $file->setAttribute('source-language', $sourceLanguage);
        }
        if ($targetLanguage == 'en' || $targetLanguage == 'default') {
            $file->setAttribute('target-language', 'en');
        } else {
            $file->setAttribute('target-language', $targetLanguage);
        }
        if (!empty($keyTranslation)) {
            $file->setAttribute('product-name', $keyTranslation);
        }
        $file->setAttribute('original', 'messages');
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('date', date('c'));

        $header = $domtree->createElement('header');
        $file->appendChild($header);

        $body = $domtree->createElement('body');

        foreach ($data as $key => $value) {
            $item = $domtree->createElement('trans-unit');
            $item->setAttribute('id', (string)$key);

            $notes = [];

            if (!empty($value['_label'])) {
                $notes['label'] = $this->noteLabel('export.field.label', 'Label') . ': ' . $value['_label'];
                $item->setAttribute('resname', $value['_label']);
            }

            if (!empty($value['_maxLength'])) {
                $notes['maxLength'] = $this->noteLabel('export.field.maxLength', 'Max length') . ': ' . $value['_maxLength'];

                $prop = $domtree->createElement('prop');
                $prop->setAttribute('group', 'validation');
                $prop->setAttribute('type', 'max-length');
                $prop->appendChild($domtree->createTextNode((string)$value['_maxLength']));
                $item->appendChild($prop);
            }

            if (!empty($value['_html'])) {
                $item->setAttribute('datatype', 'html');
            }

            [$sourceText, $targetText] = $this->resolveSourceAndTarget($value, $targetLanguage);

            $source = $domtree->createElement('source');
            $source->appendChild($domtree->createTextNode($sourceText));
            $target = $domtree->createElement('target');
            $target->appendChild($domtree->createTextNode($targetText));

            if (!empty($value['_state'])) {
                $state = $this->resolveState($value, $sourceText, $targetText);
                // 1.2 has no state vocabulary TYPO3 reads, it acts on "approved" instead
                $target->setAttribute('state', $state);
                $item->setAttribute('approved', self::isApprovedState($state) ? 'yes' : 'no');
            }

            $item->appendChild($source);
            $item->appendChild($target);

            if (!empty($value['_notes'])) {
                $notes = array_merge($notes, $value['_notes']);
            }
            if (!empty($value['_table_reference'])) {
                $notes = array_merge($notes, [$value['_table_reference']]);
            }

            if (!empty($notes)) {
                $i = 1;
                $contextGroup = $domtree->createElement('context-group');
                $contextGroup->setAttribute('purpose', 'location');
                $hasContext = false;
                foreach ($notes as $noteKey => $note) {
                    $priority = $i;

                    if ($noteKey == 'label') {
                        $contextLabel = $domtree->createElement('context');
                        $contextLabel->setAttribute('context-type', 'recordtitle');
                        $contextLabel->appendChild($domtree->createTextNode((string)$note));
                        $contextGroup->appendChild($contextLabel);
                        $hasContext = true;
                    } else if ($noteKey == 'database') {
                        $contextLabel = $domtree->createElement('context');
                        $contextLabel->setAttribute('context-type', 'database');
                        $contextLabel->appendChild($domtree->createTextNode((string) $note));
                        $contextGroup->appendChild($contextLabel);
                        $hasContext = true;
                        // This doesn't need to be in note
                        continue;
                    }

                    $noteLabel = $domtree->createElement('note');
                    $noteLabel->setAttribute('priority', (string)$priority);
                    $noteLabel->appendChild($domtree->createTextNode((string)$note));
                    $item->appendChild($noteLabel);

                    $i++;
                }
                if ($hasContext) {
                    $item->appendChild($contextGroup);
                }
            }

            $body->appendChild($item);
        }

        $file->appendChild($body);
        $xmlRoot->appendChild($file);
        $domtree->appendChild($xmlRoot);

        return $domtree->saveXML();
    }

    /**
     * XLIFF 2.x uses <unit> with a nested <segment> instead of <trans-unit>, and carries the
     * translation status in the segment state attribute.
     *
     * @return false|string
     */
    protected function dataToXlf20(array $data, string $targetLanguage, string $sourceLanguage = '', string $keyTranslation = '')
    {
        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;

        $xmlRoot = $domtree->createElementNS(self::XLIFF_20_NAMESPACE, 'xliff');
        $xmlRoot->setAttribute('version', self::VERSION_20);
        $xmlRoot->setAttribute('srcLang', $sourceLanguage !== '' ? $sourceLanguage : 'en');
        $xmlRoot->setAttribute('trgLang', ($targetLanguage === 'default') ? 'en' : $targetLanguage);

        $file = $domtree->createElement('file');
        $file->setAttribute('id', $keyTranslation !== '' ? $keyTranslation : 'messages');

        foreach ($data as $key => $value) {
            $unit = $domtree->createElement('unit');
            $unit->setAttribute('id', (string)$key);
            // Symfony's XLIFF 2.0 loader keys a unit by "name" and only falls back to "id",
            // so both have to carry the key. The human label goes into the notes below.
            $unit->setAttribute('name', (string)$key);

            $notes = [];
            if (!empty($value['_label'])) {
                $notes[] = $this->noteLabel('export.field.label', 'Label') . ': ' . $value['_label'];
            }
            if (!empty($value['_maxLength'])) {
                $notes[] = $this->noteLabel('export.field.maxLength', 'Max length') . ': ' . $value['_maxLength'];
            }
            foreach ((array)($value['_notes'] ?? []) as $noteKey => $note) {
                if ($noteKey === 'database') {
                    continue;
                }
                $notes[] = (string)$note;
            }
            if (!empty($value['_table_reference'])) {
                $notes[] = (string)$value['_table_reference'];
            }

            if ($notes !== []) {
                $notesElement = $domtree->createElement('notes');
                foreach ($notes as $note) {
                    $noteElement = $domtree->createElement('note');
                    $noteElement->appendChild($domtree->createTextNode($note));
                    $notesElement->appendChild($noteElement);
                }
                $unit->appendChild($notesElement);
            }

            [$sourceText, $targetText] = $this->resolveSourceAndTarget($value, $targetLanguage);

            $segment = $domtree->createElement('segment');
            $segment->setAttribute('state', $this->resolveState($value, $sourceText, $targetText));

            $source = $domtree->createElement('source');
            $source->appendChild($domtree->createTextNode($sourceText));
            $target = $domtree->createElement('target');
            $target->appendChild($domtree->createTextNode($targetText));

            $segment->appendChild($source);
            $segment->appendChild($target);
            $unit->appendChild($segment);
            $file->appendChild($unit);
        }

        $xmlRoot->appendChild($file);
        $domtree->appendChild($xmlRoot);

        return $domtree->saveXML();
    }

    /**
     * Whether a state means the translation may be published.
     *
     * TYPO3 reads it the same way: with LANG.requireApprovedLocalizations on, which is the
     * default, a unit in state "initial" or "translated" is skipped and the label falls back to
     * its source. Only "reviewed" and "final" reach the frontend.
     */
    public static function isApprovedState(string $state): bool
    {
        return in_array($state, self::APPROVED_STATES, true);
    }

    /**
     * The state of one entry: what the data says, or what the values imply when it says nothing.
     */
    protected function resolveState(array $value, string $sourceText, string $targetText): string
    {
        $state = (string)($value['_state'] ?? '');
        if (in_array($state, [self::STATE_INITIAL, self::STATE_TRANSLATED, self::STATE_REVIEWED, self::STATE_FINAL], true)) {
            return $state;
        }

        // an untranslated value repeats the source, that is not a translation yet
        return ($targetText !== '' && $targetText !== $sourceText) ? self::STATE_TRANSLATED : self::STATE_INITIAL;
    }

    /**
     * Label for a note written into the exported file.
     *
     * Falls back to a plain english word when the localization stack is not available, which keeps
     * the service usable outside a full TYPO3 request, for example in a unit test or a CLI import.
     */
    protected function noteLabel(string $key, string $fallback): string
    {
        try {
            $label = LocalizationUtility::translate(
                'LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:' . $key
            );
        } catch (\Throwable $e) {
            $label = null;
        }

        return ($label !== null && $label !== '') ? $label : $fallback;
    }

    /**
     * @return array{0: string, 1: string} source and target text of one entry
     */
    public function resolveSourceAndTarget(array $value, string $targetLanguage): array
    {
        if ($targetLanguage === 'en' || $targetLanguage === 'default') {
            $text = (string)($value[$targetLanguage] ?? '');

            return [$text, $text];
        }

        $source = $value['default'] ?? null;
        $target = (string)($value[$targetLanguage] ?? '');

        return [(string)($source ?? $target), $target];
    }

    /**
     * Reads an XLIFF file of either version into a normalized structure.
     *
     * @return array<string, array{source: string, target: string, state: string, approved: bool, maxLength: int|null, notes: string[], html: bool}>
     */
    public function parse(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($input, LIBXML_PARSEHUGE | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            return [];
        }

        return $this->detectVersion($document) === self::VERSION_20
            ? $this->parseXliff20($document)
            : $this->parseXliff12($document);
    }

    /**
     * The root version attribute decides, a file declaring 2.x anywhere in its major is read as 2.x.
     */
    public function detectVersion(\DOMDocument $document): string
    {
        $version = (string)$document->documentElement?->getAttribute('version');

        return str_starts_with($version, '2') ? self::VERSION_20 : self::VERSION_12;
    }

    /**
     * @return array<string, array>
     */
    protected function parseXliff12(\DOMDocument $document): array
    {
        $return = [];

        foreach ($document->getElementsByTagNameNS('*', 'trans-unit') as $unit) {
            $id = (string)$unit->getAttribute('id');
            if ($id === '') {
                continue;
            }

            $maxLength = null;
            foreach ($unit->getElementsByTagNameNS('*', 'prop') as $prop) {
                if ($prop->getAttribute('type') === 'max-length') {
                    $maxLength = (int)trim($prop->textContent);
                }
            }

            $notes = [];
            foreach ($unit->getElementsByTagNameNS('*', 'note') as $note) {
                $notes[] = trim($note->textContent);
            }

            // 1.2 carries approval on the trans-unit itself. A missing attribute means approved,
            // which is how TYPO3 reads it too: only an explicit approved="no" holds a label back.
            $approvedAttribute = $unit->getAttribute('approved');
            $approved = $approvedAttribute === '' || $approvedAttribute === 'yes';
            $state = (string)($this->firstChildText($unit, 'target', true) ?? '');

            $return[$id] = [
                'source' => $this->firstChildText($unit, 'source') ?? '',
                'target' => $this->firstChildText($unit, 'target') ?? '',
                'state' => $state !== '' ? $state : ($approved ? self::STATE_FINAL : self::STATE_TRANSLATED),
                'approved' => $approved,
                'maxLength' => $maxLength,
                'notes' => $notes,
                'html' => $unit->getAttribute('datatype') === 'html',
            ];
        }

        return $return;
    }

    /**
     * @return array<string, array>
     */
    protected function parseXliff20(\DOMDocument $document): array
    {
        $return = [];

        foreach ($document->getElementsByTagNameNS('*', 'unit') as $unit) {
            $id = (string)$unit->getAttribute('id');
            if ($id === '') {
                continue;
            }

            $notes = [];
            foreach ($unit->getElementsByTagNameNS('*', 'note') as $note) {
                $notes[] = trim($note->textContent);
            }

            // a unit may be split into several segments, they belong to one value
            $sourceParts = [];
            $targetParts = [];
            $state = self::STATE_INITIAL;

            foreach ($unit->getElementsByTagNameNS('*', 'segment') as $segment) {
                $sourceParts[] = $this->firstChildText($segment, 'source') ?? '';
                $targetParts[] = $this->firstChildText($segment, 'target') ?? '';

                $segmentState = (string)$segment->getAttribute('state');
                if ($segmentState !== '') {
                    $state = $segmentState;
                }
            }

            $return[$id] = [
                'source' => implode('', $sourceParts),
                'target' => implode('', $targetParts),
                'state' => $state,
                'approved' => in_array($state, self::APPROVED_STATES, true),
                'maxLength' => null,
                'notes' => $notes,
                'html' => false,
            ];
        }

        return $return;
    }

    /**
     * @param bool $readState return the state attribute instead of the text
     */
    protected function firstChildText(\DOMElement $parent, string $localName, bool $readState = false): ?string
    {
        foreach ($parent->getElementsByTagNameNS('*', $localName) as $child) {
            return $readState ? (string)$child->getAttribute('state') : $child->textContent;
        }

        return null;
    }

    /**
     * @param string $input
     * @param array $sourceAndTarget - [sourceKey (default), targetKey (de)]
     * @param string $sourcePath the node the wanted translation is read from,
     *                           usually "target" but also "source"
     */
    public function xlfToData(string $input, $sourceAndTarget = [], string $sourcePath = 'target')
    {
        $parsed = $this->parse($input);
        $return = [];

        $enableSource = !empty($sourceAndTarget);
        $sourceKey = $enableSource ? $sourceAndTarget[0] : null;
        $targetKey = $enableSource ? $sourceAndTarget[1] : null;

        foreach ($parsed as $id => $entry) {
            $value = $entry[$sourcePath] ?? '';

            if ($enableSource) {
                $insert = [
                    $sourceKey => $entry['source'],
                    $targetKey => $value,
                ];
                // an entry without any content on either side carries no information
                if (trim($entry['source']) === '' && trim((string)$value) === '') {
                    continue;
                }
            } else {
                $insert = $value;
                if (trim((string)$value) === '') {
                    continue;
                }
            }

            $return[$id] = $insert;
        }

        return $return;
    }
}

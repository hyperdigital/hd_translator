<?php

namespace Hyperdigital\HdTranslator\Services;

use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class XlfService
{
    /**
     * @param array $data - array of ['default => 'SomeString', 'de' => 'translated string', '_label' => 'LABEL', '_html' => true ,'notes' = [] ]
     * @param string $targetLanguage
     * @param string $sourceLanguage
     * @param string $keyTranslation
     * @return false|string
     */
    public function dataToXlf(array $data, string $targetLanguage, string $sourceLanguage = '', string $keyTranslation = '')
    {
        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;
        $xmlRoot = $domtree->createElement('xliff');
        $xmlRoot->setAttribute('version', '1.2');

        $file = $domtree->createElement('file');
        if (!empty($sourceLanguage)) {
            $file->setAttribute('source-language', $sourceLanguage);
        }
        $file->setAttribute('target-language', $targetLanguage);
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
            $item->setAttribute('id', $key);

            $notes = [];

            if (!empty($value['_label'])) {
                $notes[] = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:export.field.label') . ': ' . $value['_label'];
                $item->setAttribute('resname', $value['_label']);

            }
            if (!empty($value['_html'])) {
                $item->setAttribute('datatype', 'html');
            }

            $source = $domtree->createElement('source');

            if ($targetLanguage == 'en' || $targetLanguage == 'default') {
                $valSource = $domtree->createTextNode($value[$targetLanguage]);

                $source->appendChild($valSource);
                $item->appendChild($source);
            } else {
                $target = $domtree->createElement('target');
                $valSource = $domtree->createTextNode((!is_null($value['default'])) ? $value['default'] : $value[$targetLanguage]);
                $valTarget = $domtree->createTextNode($value[$targetLanguage]);

                $source->appendChild($valSource);
                $target->appendChild($valTarget);
                $item->appendChild($source);
                $item->appendChild($target);
            }
            if (!empty($value['_notes'])) {
                $notes = array_merge($notes, $value['_notes']);
            }

            if (!empty($notes)) {
                $noteLabel = $domtree->createElement('note');
                $noteText = $domtree->createTextNode(implode("\n", $notes));
                $noteLabel->appendChild($noteText);
                $item->appendChild($noteLabel);
            }


            $body->appendChild($item);
        }

        $file->appendChild($body);
        $xmlRoot->appendChild($file);
        $domtree->appendChild($xmlRoot);

        return $domtree->saveXML();
    }
}

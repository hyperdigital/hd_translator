<?php

namespace Hyperdigital\HdTranslator\Services;

class XlfService
{
    public function dataToXlf($data, $targetLanguage, $sourceLanguage = '', $keyTranslation = '')
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


            $body->appendChild($item);
        }

        $file->appendChild($body);
        $xmlRoot->appendChild($file);
        $domtree->appendChild($xmlRoot);

        return $domtree->saveXML();
    }
}
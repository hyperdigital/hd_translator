<?php
namespace Hyperdigital\HdTranslator\Services;

class FlagService
{
    public static $flags = [
        'empty' => '', //
        'default' => 'GB', // English
        'af' => '', // Afrikaans
        'ar' => '', // Arabic
        'bs' => '', // Bosnian
        'bg' => '', // Bulgarian
        'ca' => '', // Catalan
        'ch' => '', // Chinese (Simple)
        'cs' => 'CZ', // Czech
        'cy' => '', // Welsh
        'da' => '', // Danish
        'de' => 'DE', // German
        'el' => '', // Greek
        'eo' => '', // Esperanto
        'es' => '', // Spanish
        'et' => '', // Estonian
        'eu' => '', // Basque
        'fa' => '', // Persian
        'fi' => '', // Finnish
        'fo' => '', // Faroese
        'fr' => 'FR', // French
        'fr_CA' => 'CA', // French (Canada)
        'gl' => '', // Galician
        'he' => '', // Hebrew
        'hi' => '', // Hindi
        'hr' => '', // Croatian
        'hu' => '', // Hungarian
        'is' => '', // Icelandic
        'it' => '', // Italian
        'ja' => '', // Japanese
        'ka' => '', // Georgian
        'kl' => '', // Greenlandic
        'km' => '', // Khmer
        'ko' => '', // Korean
        'lt' => '', // Lithuanian
        'lv' => '', // Latvian
        'mi' => '', // Maori
        'mk' => '', // Macedonian
        'ms' => '', // Malay
        'nl' => '', // Dutch
        'no' => '', // Norwegian
        'pl' => '', // Polish
        'pt' => 'PT', // Portuguese
        'pt_BR' => 'BR', // Brazilian Portuguese
        'ro' => '', // Romanian
        'ru' => 'RU', // Russian
        'rw' => '', // Kinyarwanda
        'sk' => '', // Slovak
        'sl' => '', // Slovenian
        'sn' => '', // Shona (Bantu)
        'sq' => '', // Albanian
        'sr' => '', // Serbian
        'sv' => '', // Swedish
        'th' => '', // Thai
        'tr' => '', // Turkish
        'uk' => '', // Ukrainian
        'vi' => '', // Vietnamese
        'zh' => '', // Chinese (Trad)
    ];

    /**
     * @param string $language
     * @return string short name of the flag
     */
    public static function getFlagForLanguage($language)
    {
        if (empty(self::$flags[$language])) {
            return self::$flags['empty'];
        }

        return self::$flags[$language];
    }
}
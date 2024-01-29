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
        'ca' => 'ES', // Catalan
        'ch' => 'CN', // Chinese (Simple)
        'cs' => 'CZ', // Czech
        'cy' => 'GB-WLS', // Welsh
        'da' => 'DK', // Danish
        'de' => 'DE', // German
        'el' => 'GR', // Greek
        'en_US' => 'US',
        'eo' => 'ES', // Esperanto
        'es' => 'ES', // Spanish
        'et' => 'EE', // Estonian
        'eu' => 'ES', // Basque
        'fa' => '', // Persian
        'fi' => 'FI', // Finnish
        'fo' => '', // Faroese
        'fr' => 'FR', // French
        'fr_CA' => 'CA', // French (Canada)
        'gl' => '', // Galician
        'he' => 'IL', // Hebrew
        'hi' => 'IN', // Hindi
        'hr' => 'HR', // Croatian
        'hu' => 'HU', // Hungarian
        'is' => 'IS', // Icelandic
        'it' => 'IT', // Italian
        'ja' => 'JP', // Japanese
        'ka' => '', // Georgian
        'kl' => '', // Greenlandic
        'km' => '', // Khmer
        'ko' => 'KO', // Korean
        'lt' => '', // Lithuanian
        'lv' => '', // Latvian
        'mi' => '', // Maori
        'mk' => '', // Macedonian
        'ms' => '', // Malay
        'nl' => '', // Dutch
        'no' => 'NO', // Norwegian
        'pl' => 'PL', // Polish
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
        'sv' => 'SE', // Swedish
        'th' => '', // Thai
        'tr' => '', // Turkish
        'uk' => 'UK', // Ukrainian
        'us' => 'US',
        'vi' => '', // Vietnamese
        'zh' => 'CN', // Chinese (Trad)
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

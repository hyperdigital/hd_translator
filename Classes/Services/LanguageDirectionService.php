<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

/**
 * Resolves the writing direction of a language.
 *
 * The module edits content in languages the backend itself is not running in, so the
 * direction of an input field cannot be inherited from the backend. A translator typing
 * Arabic into a left to right backend still needs a right to left field.
 */
class LanguageDirectionService
{
    public const DIRECTION_RTL = 'rtl';
    public const DIRECTION_LTR = 'ltr';

    /**
     * Languages written from right to left, by ISO 639 code.
     *
     * Only languages whose standard orthography is right to left are listed. Languages
     * written in both directions depending on the region, Kurmanji Kurdish for example,
     * are deliberately left out, a wrong direction is worse than the inherited one.
     *
     * @var string[]
     */
    protected const RTL_LANGUAGES = [
        'ar',   // Arabic
        'arc',  // Aramaic
        'ckb',  // Central Kurdish (Sorani)
        'dv',   // Divehi
        'fa',   // Persian
        'he',   // Hebrew
        'iw',   // Hebrew (legacy code)
        'khw',  // Khowar
        'ks',   // Kashmiri
        'nqo',  // N'Ko
        'pnb',  // Western Punjabi
        'ps',   // Pashto
        'sd',   // Sindhi
        'syr',  // Syriac
        'ug',   // Uyghur
        'ur',   // Urdu
        'yi',   // Yiddish
    ];

    /**
     * @param string $language language key, accepts "ar", "ar-EG", "ar_EG" and "default"
     */
    public static function isRightToLeft(string $language): bool
    {
        return in_array(self::normalize($language), self::RTL_LANGUAGES, true);
    }

    /**
     * @return string "rtl" or "ltr", ready for a dir attribute
     */
    public static function getDirection(string $language): string
    {
        return self::isRightToLeft($language) ? self::DIRECTION_RTL : self::DIRECTION_LTR;
    }

    /**
     * Reduces a language key to its base subtag, "pt-BR" and "pt_BR" both become "pt".
     */
    protected static function normalize(string $language): string
    {
        $language = strtolower(trim($language));
        $language = str_replace('_', '-', $language);

        return explode('-', $language)[0];
    }
}

<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\ViewHelpers;

use Hyperdigital\HdTranslator\Services\LanguageDirectionService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Returns "rtl" or "ltr" for a language key, for use in a dir attribute.
 *
 * <translator:languageDirection language="ar" /> => rtl
 */
class LanguageDirectionViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument(
            'language',
            'string',
            'Language key, for example "de", "ar" or "ar-EG"',
            false,
            ''
        );
    }

    public function render(): string
    {
        return LanguageDirectionService::getDirection((string)($this->arguments['language'] ?? ''));
    }
}

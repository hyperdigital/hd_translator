<?php
namespace Hyperdigital\HdTranslator\ViewHelpers;

use Hyperdigital\HdTranslator\Services\FlagService;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Resolves the flag icon path of a language key.
 *
 * Fluid 5, shipped with TYPO3 14, removed renderStatic() and the CompilableInterface,
 * and initializeArguments() has to declare its void return type.
 */
class GetFlagNameViewHelper extends AbstractViewHelper
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
            'Short language name'
        );
    }

    public function render(): string
    {
        $flag = FlagService::getFlagForLanguage($this->arguments['language'] ?? '');

        if (empty($flag)) {
            return 'EXT:hd_translator/Resources/Public/Icons/empty_flag.png';
        }

        return 'EXT:core/Resources/Public/Icons/Flags/' . strtolower($flag) . '.webp';
    }
}

<?php
namespace Hyperdigital\HdTranslator\ViewHelpers;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\Core\ViewHelper\Facets\CompilableInterface;

class GetFlagNameViewHelper extends AbstractViewHelper
{
    /**
     * @var bool
     */
    protected $escapeOutput = false;

    public function initializeArguments()
    {

        $this->registerArgument(
            'language',
            'string',
            'Short language name'
        );
    }

    /**
     * @param array $arguments
     * @param \Closure $renderChildrenClosure
     * @param RenderingContextInterface $renderingContext
     * @return string
     */
    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ) {
        $flag = \Hyperdigital\HdTranslator\Services\FlagService::getFlagForLanguage($arguments['language']);
        if (empty($flag)) {
            return 'EXT:hd_translator/Resources/Public/Icons/empty_flag.png';
        }

        return 'EXT:core/Resources/Public/Icons/Flags/'.strtoupper($flag).'.png';
    }
}

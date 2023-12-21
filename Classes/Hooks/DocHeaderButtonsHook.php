<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Hooks;

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use Psr\Http\Message\ServerRequestInterface;

class DocHeaderButtonsHook
{
    private function getRequest(): ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'];
    }

    public function __invoke(ModifyButtonBarEvent $event): void
    {
        if ($request = $this->getRequest()) {
            $currentUid = (int) $request->getQueryParams()['id'];
            $module = $request->getAttribute('module');
            $route = $request->getAttribute('route');

            if ($module && $module->getIdentifier() == 'web_list') {
                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                $button = $event->getButtonBar()->makeLinkButton();
                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', Icon::SIZE_SMALL));
                $button->setTitle($label);
                $button->setShowLabelText($label);
                $button->setHref($this->getPageContentExportLink($currentUid));
                $buttonBar = $event->getButtons();
                $buttonBar[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                $event->setButtons($buttonBar);
            } else if ($route && $route->getPath() == '/record/edit') {
                $queryParams = $request->getQueryParams();
                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                $button = $event->getButtonBar()->makeLinkButton();
                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', Icon::SIZE_SMALL));
                $button->setTitle($label);
                $button->setShowLabelText($label);
                foreach ($queryParams['edit'] as $table => $idArray) {
                    foreach ($idArray as $id => $action) {
                        $button->setHref($this->getRowExportLink($id, $table));
                    }
                }
                $buttonBar = $event->getButtons();
                $buttonBar[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                $event->setButtons($buttonBar);
            }


        }
    }

    protected function getRowExportLink($uid, $tablename)
    {
        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
        $uri = $uriBuilder->buildUriFromRoutePath(
            '/module/web/HdTranslatorHdTranslatorEngine',
            [
                'action' => 'exportTableRowIndex',
                'controller' => 'Be\Translator',
                'tablename' => $tablename,
                'rowUid' => (int)$uid
            ]
        );

        return $uri;
    }

    public function getPageContentExportLink($uid)
    {
        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
        $uri = $uriBuilder->buildUriFromRoutePath(
            '/module/web/HdTranslatorHdTranslatorEngine',
            [
                'action' => 'pageContentExport',
                'controller' => 'Be\Translator',
                'page' => $uid,
            ]
        );

        return $uri;
    }
}

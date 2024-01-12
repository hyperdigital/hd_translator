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
use TYPO3\CMS\Recordlist\Event\ModifyRecordListHeaderColumnsEvent;
use TYPO3\CMS\Recordlist\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Recordlist\Event\ModifyRecordListTableActionsEvent;

class DocHeaderButtonsHook
{
    private function getRequest(): ServerRequestInterface
    {
        return $GLOBALS['TYPO3_REQUEST'];
    }

    public function modifyButtonBarColumns(ModifyButtonBarEvent $event): void
    {
        $request = $this->getRequest();
        if ($request && $GLOBALS['BE_USER']->check('modules', 'hd_translator_engine')) {
            $currentUid = (int) $request->getQueryParams()['id'];
            $module = $request->getAttribute('module');
            $route = $request->getAttribute('route');

            if ($module && ( $module->getIdentifier() == 'web_list' || $module->getIdentifier() == 'web_layout') && $currentUid > 0) {
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
                $enableButton = false;
                foreach ($queryParams['edit'] as $table => $idArray) {
                    if(!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
                        foreach ($idArray as $id => $action) {
                            $enableButton = true;
                            $button->setHref($this->getRowExportLink($id, $table));
                        }
                    }
                }
                if ($enableButton) {
                    $buttonBar = $event->getButtons();
                    $buttonBar[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                    $event->setButtons($buttonBar);
                }
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

    public function modifyRecordActions(\TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent $event): void
    {
        $currentTable = $event->getTable();
        $uid = $event->getRecord()['uid'];

        // Add a custom action for a custom table in the secondary action bar, before the "move" action
        if (!empty($uid) && $GLOBALS['BE_USER']->check('modules', 'hd_translator_engine')) {
            if ($currentTable == 'pages') {
                $url = $this->getPageContentExportLink($uid);
            } else if(!empty($GLOBALS['TCA'][$currentTable]['ctrl']['languageField'])) {
                $url = $this->getRowExportLink($uid, $currentTable);
            }
            if (!empty($url)) {
                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                $icon = '<span class="t3js-icon icon icon-size-small icon-state-default">
	<span class="icon-markup">
<span class="icon-markup">
	    <svg class="icon-color" viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="m8 2.3652c-2.505 0-4.3935 1.5188-5.2051 3.4785-.81162 1.9597-.55038 4.3695 1.2207 6.1406s4.1809 2.0323 6.1406 1.2207c1.9597-.81162 3.4785-2.7001 3.4785-5.2051 0-3.1106-2.5241-5.6348-5.6348-5.6348zm-1.0547 1.1113c-.45672.64608-.83942 1.3404-1.0781 2.0957-.52071-.10777-1.0296-.26434-1.5273-.45117.6537-.83867 1.5716-1.405 2.6055-1.6445zm2.1094 0c1.0339.2395 1.9518.80587 2.6055 1.6445-.49773.18683-1.0066.34341-1.5273.45117-.24183-.75357-.62412-1.4485-1.0781-2.0957zm-1.0547.13281c.51117.62602.9077 1.3338 1.1719 2.0977-.7795.087667-1.5643.087667-2.3438 0 .26418-.76386.6607-1.4716 1.1719-2.0977zm-4.1836 2.3184c.58014.23776 1.1788.427 1.791.5625-.20138.99971-.20112 2.0276 0 3.0273-.61223.13254-1.2104.32171-1.791.55664-.32118-.64637-.51119-1.3515-.51172-2.0742.000525-.72204.1911-1.4264.51172-2.0723zm8.3379 0c.64959 1.3091.64778 2.8355-.002 4.1445-.58051-.23472-1.1789-.4223-1.791-.55469.1964-.99915.1964-2.0262 0-3.0254.61303-.13554 1.2121-.32639 1.793-.56445zm-5.5918.70703c.4764.063707.95484.10534 1.4355.10938h.0039031c.48071-.00404.95915-.045668 1.4355-.10938.17769.90579.17921 1.8366 0 2.7422-.95544-.11771-1.9196-.11771-2.875 0-.17921-.90556-.17769-1.8364 0-2.7422zm.26562 3.6582c.7795-.08767 1.5643-.08767 2.3438 0-.26418.76386-.6607 1.4716-1.1719 2.0977-.51117-.62602-.9077-1.3338-1.1719-2.0977zm-.96094.13476c.24183.75358.62412 1.4485 1.0781 2.0957-1.0339-.2395-1.9518-.80587-2.6055-1.6445.49773-.18683 1.0066-.3434 1.5273-.45117zm4.2656 0c.52071.10777 1.0296.26434 1.5273.45117-.6537.83867-1.5716 1.405-2.6055 1.6445.454-.64725.83629-1.3421 1.0781-2.0957z" color="currentColor" fill="currentColor" stroke-miterlimit="10" style="-inkscape-stroke:none"/></svg>
    </span>
	</span>
</span>
';
                $event->setAction(
                    '<a href="'.$url.'" class="dropdown-item dropdown-item-spaced">'.$icon.$label.'</a>',
                    '',
                    'secondary',
                    'move'
                );
            }
        }
    }
}

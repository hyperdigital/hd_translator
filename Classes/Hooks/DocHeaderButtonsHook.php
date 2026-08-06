<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Hooks;

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\RecordList\Event\ModifyRecordListRecordActionsEvent;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use Hyperdigital\HdTranslator\Helpers\TranslationHelper;

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
            $currentUid = (int)($request->getQueryParams()['id'] ?? 0);
            $module = $request->getAttribute('module');
            $route = $request->getAttribute('route');

            // TYPO3 14 renamed the list module from "web_list" to "records", the old identifier is
            // kept so the button also shows up on installations still using it
            $listModules = ['records', 'web_list', 'web_layout'];

            if ($module && in_array($module->getIdentifier(), $listModules, true) && $currentUid > 0) {
                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                $button = $event->getButtonBar()->makeLinkButton();
                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', IconSize::SMALL));
                $button->setTitle($label);
                $button->setShowLabelText(true);
                $button->setHref($this->getPageContentExportLink($currentUid));
                $buttonBar = $event->getButtons();
                $buttonBar[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                $event->setButtons($buttonBar);
            } else if ($route && $route->getPath() == '/record/edit') {
                $queryParams = $request->getQueryParams();
                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                $button = $event->getButtonBar()->makeLinkButton();
                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', IconSize::SMALL));
                $button->setTitle($label);
                $button->setShowLabelText(true);
                $enableButton = false;
                foreach ($queryParams['edit'] as $table => $idArray) {
                    if(!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
                        foreach ($idArray as $id => $action) {
                            $enableButton = true;
                            $pageUid = $currentUid > 0
                                ? $currentUid
                                : \Hyperdigital\HdTranslator\Helpers\TranslationHelper::getPidOfRecord($table, (int)$id);
                            $button->setHref($this->getRowExportLink($id, $table, $pageUid));
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

    /**
     * @param int $uid uid of the record
     * @param string $tablename
     * @param int $pageUid page the record lives on, needed to resolve the site configuration
     */
    protected function getRowExportLink($uid, $tablename, int $pageUid = 0): string
    {
        $parameters = [
            'action' => 'exportTableRowIndex',
            'controller' => 'Be\Translator',
            'tablename' => $tablename,
            'rowUid' => (int)$uid
        ];

        // The module has no page tree, so "id" has to be passed explicitly. Without it
        // the site (and therefore the list of languages) cannot be resolved.
        if ($pageUid > 0) {
            $parameters['id'] = $pageUid;
        }

        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);

        return (string)$uriBuilder->buildUriFromRoutePath('/module/web/HdTranslatorHdTranslatorEngine', $parameters);
    }

    public function getPageContentExportLink($uid): string
    {
        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
        $uri = $uriBuilder->buildUriFromRoutePath(
            '/module/web/HdTranslatorHdTranslatorEngine',
            [
                'action' => 'pageContentExport',
                'controller' => 'Be\Translator',
                'page' => $uid,
                // the page itself defines the site the export belongs to
                'id' => (int)$uid,
            ]
        );

        return (string)$uri;
    }

    public function modifyRecordActions(ModifyRecordListRecordActionsEvent $event): void
    {
        if (!$GLOBALS['BE_USER']->check('modules', 'hd_translator_engine')) {
            return;
        }

        // TYPO3 14 reworked this event: the table is no longer exposed directly, getRecord()
        // returns a RecordInterface instead of an array, and an action is a component object
        // instead of a rendered HTML string.
        $record = $event->getRecord();
        $currentTable = $record->getMainType();
        $uid = $record->getUid();

        if (empty($uid)) {
            return;
        }

        $url = '';
        if ($currentTable === 'pages') {
            $url = (string)$this->getPageContentExportLink($uid);
        } elseif (!empty($GLOBALS['TCA'][$currentTable]['ctrl']['languageField'])) {
            // the list module knows the page already, so no extra lookup is needed
            $pageUid = $record->getPid();
            if ($pageUid <= 0) {
                $pageUid = TranslationHelper::getPidOfRecord($currentTable, (int)$uid);
            }
            $url = (string)$this->getRowExportLink($uid, $currentTable, $pageUid);
        }

        if ($url === '') {
            return;
        }

        $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $componentFactory = GeneralUtility::makeInstance(ComponentFactory::class);

        $action = $componentFactory->createGenericButton()
            ->setTag('a')
            ->setLabel($label)
            ->setTitle($label)
            ->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', IconSize::SMALL))
            ->setHref($url);

        $event->setAction($action, 'hdtranslator_export', ActionGroup::secondary, before: 'move');
    }
}

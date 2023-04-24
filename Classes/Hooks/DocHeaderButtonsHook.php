<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Hooks;

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class DocHeaderButtonsHook
{
    public function addExportButton(
        array $params,
        ButtonBar $buttonBar
    ): array {
        $buttons = $params['buttons'];

        $parameters = GeneralUtility::_GP('edit');
        $typo3Version = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class);

        if (!empty($parameters)) {
            foreach ($parameters as $tablename => $uidArray) {
                if (!empty($uidArray)) {
                    foreach ($uidArray as $uid => $status) {
                        if ($status == 'edit') {
                            if ($tablename == 'pages') {
                                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationPageContent');
                                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                                $button = $buttonBar->makeLinkButton();
                                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', Icon::SIZE_SMALL));
                                $button->setTitle($label);
                                $button->setShowLabelText($label);
                                $button->setHref($this->getPageContentExportLink($uid));
                                $buttons[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                            } else {
                                $label = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:control.exportTranslationRow');
                                $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
                                $button = $buttonBar->makeLinkButton();
                                $button->setIcon($iconFactory->getIcon('hd_translator_icon_doc_header', Icon::SIZE_SMALL));
                                $button->setTitle($label);
                                $button->setShowLabelText($label);
                                $button->setHref($this->getRowExportLink($uid, $tablename));
                                $buttons[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
                            }
                        }
                        break 2;
                    }
                }
            }
        }
//
//        DebuggerUtility::var_dump();
//        die();
//return $buttons;
//        $contentUid = $this->getContentUid();
//        if (null !== $contentUid) {
//            /** @var IconFactory $iconFactory */
//            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
//            $button = $buttonBar->makeLinkButton();
//            $button->setIcon($iconFactory->getIcon('ext-dce-dce', Icon::SIZE_SMALL));
//            $button->setTitle(LocalizationUtility::translate('editDceOfThisContentElement', 'dce'));
//            $button->setOnClick(
//                'window.open(\'' . $this->getDceEditLink($contentUid) . '\', \'editDcePopup\', ' .
//                '\'height=768,width=1024,status=0,menubar=0,scrollbars=1\')'
//            );
//            $buttons[ButtonBar::BUTTON_POSITION_LEFT][][] = $button;
//        }

        return $buttons;
    }

    protected function getRowExportLink($uid, $tablename)
    {
        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
        $uri = $uriBuilder->buildUriFromRoutePath(
            '/module/web/HdTranslatorHdTranslatorEngine',
            [
                'tx_hdtranslator_web_hdtranslatorhdtranslatorengine' => [
                    'action' => 'exportTableRowIndex',
                    'controller' => 'Be\Translator',
                    'tablename' => $tablename,
                    'rowUid' => (int)$uid
                ]
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
                'tx_hdtranslator_web_hdtranslatorhdtranslatorengine' => [
                    'action' => 'pageContentExport',
                    'controller' => 'Be\Translator',
                    'page' => $uid,
                ]
            ]
        );

        return $uri;
    }
}

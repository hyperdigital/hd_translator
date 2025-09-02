<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Controller\Be;

use Hyperdigital\HdTranslator\Services\DeeplApiService;
use Hyperdigital\HdTranslator\Services\XlfService;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Localization\LocalizationFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extensionmanager\Domain\Model\Extension;
use TYPO3\CMS\Extensionmanager\Domain\Repository\ExtensionRepository;
use TYPO3\CMS\Extensionmanager\Utility\DependencyUtility;
use TYPO3\CMS\Extensionmanager\Utility\ExtensionModelUtility;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Database\Connection;
use \TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

class TranslatorController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    protected $storage = '';
    protected $listOfPossibleLanguages = [];
    protected $conigurationFile = 'locallangConf.php';
    protected $defaultFilename = 'locallang.xlf';
    protected $relativePathToLangFilesInExt = 'Resources/Private/Language';
    protected $relativePathToLangFilesInExtContentBlocks = 'ContentBlocks/ContentElements';
    protected $backupExtension = '.backup';
    protected $langFiles = [];
    protected $languageService;

    /**
     * @var array
     * Used in database import. It starts with original (default) language and chnaged items are overwritten
     */
    protected $originalData = [];

    /**
     * @var array
     * Used in database import. It always holds original (default) language.
     */
    protected $superOriginalData = [];

    protected $pageUid = 0;
    protected $pageData = [];
    protected $moduleTemplate;
    protected $deeplApiKey;

    public function __construct(
        protected readonly ListUtility $listUtility,
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly FlexFormService $flexFormService,
        protected readonly PageRepository $pageRepository,
        protected UriBuilder $uriBuilder
    )
    {
        $this->languageService = $languageService = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);;
    }

    public function initializeAction(): void
    {
        parent::initializeAction();

        $this->storage = \Hyperdigital\HdTranslator\Helpers\TranslationHelper::getStoragePath();
        $this->listOfPossibleLanguages = GeneralUtility::makeInstance(Locales::class)->getLanguages();
        $this->deeplApiKey = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'deeplApiKey') ?? '';

        if (empty($this->storage) && \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($this->storage . $this->conigurationFile)) {
                require $this->storage . $this->conigurationFile;
            } else {
                $this->redirect('syncLocallangs');
            }
        }

        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $currentPid = $this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? null;
        if ($currentPid) {
            $this->pageUid = (int)$currentPid;
            $this->pageData = $this->pageRepository->getPage($this->pageUid, true);
        }
    }

    // HELPERS
    /**
     * @param string $languageTranslation
     * @param string $keyTranslation
     *
     * Description: Get full path of the translation
     */
    protected function getTranslationPath($languageTranslation, $keyTranslation)
    {
        if ($languageTranslation == 'en' || $languageTranslation == 'default') {
            $filename = $keyTranslation . '.xlf';
        } else {
            $filename = $languageTranslation . '.' . $keyTranslation . '.xlf';
        }

        $path = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);

        return $path;
    }

    /**
     * @param $value
     * @param $array
     * @param $keys
     *
     * Description: Cleanup multidimensional array
     */
    public function multidimensionalArray($value, &$array, $keys)
    {
        if (count($keys) == 1) {
            $array[$keys[0]] = $value;
        } else {
            $nextKey = $keys[0];
            unset($keys[0]);
            $keys = array_values($keys);
            $this->multidimensionalArray($value, $array[$nextKey], $keys);
        }
    }

    // STRING TRANSLATIONS
    /**
     * Template: Be/Translator/Index
     * Description: Initial point where came user warning if the storage is missing
     * or no language is enabled for the translation. If all pass, then a categories list is shown.
     * The categories leads user into listAction.
     *
     */
    public function indexAction()
    {
        if (empty($this->storage)) {
            $this->moduleTemplate->assign('emptyStorage', 1);
        } else {
            $this->indexMenu();

            $data = [];
            if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'])) {
                foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'] as $key => $value) {
                    $category = '-';
                    if (!empty($value['category'])) {
                        $category = $value['category'];
                    }

                    $data[$category][$key] = [
                        'label' => (!empty($value['label'])) ? $value['label'] : $key,
                        'languages' => $value['languages']
                    ];
                }
            }

            if (!empty($this->pageData)) {
                $this->moduleTemplate->assign('pageData', $this->pageData);
            }
            $this->moduleTemplate->assign('categories', $data);
            $this->moduleTemplate->assign('enabledSync', \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs'));
        }

        return $this->moduleTemplate->renderResponse('Be/Translator/Index');
    }

    /**
     * @param string $category
     *
     * Template: Be/Translator/List
     * Description: Shows available translations for given category as a list of "translation files"
     * with possibility to choose language for given file.
     * This leads user into detailAction.
     */
    public function listAction(string $category)
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('index'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if (!empty($this->pageData)) {
            $this->moduleTemplate->assign('pageData', $this->pageData);
        }

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'])) {
            $data = [];
            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'] as $key => $value) {
                $categoryData = '-';
                if (!empty($value['category'])) {
                    $categoryData = $value['category'];
                }

                if ($category == $categoryData) {
                    $data[$key] = [
                        'label' => (!empty($value['label'])) ? $value['label'] : $key,
                        'languages' => $value['languages'],
                        'availableLanguages' => [],
                    ];
                    foreach ($this->listOfPossibleLanguages as $langKey => $tempLang) {
                        if ($langKey == 'en' || $langKey == 'default') {
                            $filename = $key . '.xlf';
                        } else {
                            $filename = $langKey . '.' . $key . '.xlf';
                        }

                        if (!empty($this->pageUid)) {
                            $filename = $this->pageUid . '.' . $filename;
                        }

                        $path = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);
                        if (file_exists($path)) {
                            $data[$key]['availableLanguages'][$langKey] = $tempLang;
                        }
                    }
                }
            }

            $this->moduleTemplate->assign('data', $data);
            $this->moduleTemplate->assign('languagesArray', $this->listOfPossibleLanguages);
            $this->moduleTemplate->assign('category', $category);
        }

        return $this->moduleTemplate->renderResponse('Be/Translator/List');
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     * @param boolean $saved
     * @param boolean $emptyImport
     * @param boolean $forceNew
     *
     * Template: Be/Translator/Detail
     * Description: Main string translation section.
     * If the current translation doesn't exist, but there is a backup,
     * user is redirected into chooseBackupOrNewAction.
     */
    public function detailAction($keyTranslation, $languageTranslation, $saved = false, $emptyImport = false, $forceNew = false)
    {
        // Check if backup exists
        if (empty($forceNew)) {
            $path = $this->getTranslationPath($languageTranslation, $keyTranslation);

            if (!file_exists($path) && file_exists($path . $this->backupExtension)) {
                return $this->redirect('chooseBackupOrNew', null, null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
            }
        }

        if ($saved) {
            $this->moduleTemplate->addFlashMessage(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('flashMessages.sucecssfullySaved', 'hd_translator'));
        }
        if ($emptyImport) {
            $this->moduleTemplate->addFlashMessage(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('flashMessages.noDataToImport', 'hd_translator'), '', \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR);
        }


        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('hd_translator');
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'] as $lang) {
                $item = $menu->makeMenuItem()->setTitle('[' . strtoupper($lang) . '] ' . $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['label'])
                    ->setHref($uriBuilder->reset()->uriFor('detail', ['keyTranslation' => $keyTranslation, 'languageTranslation' => $lang]))
                    ->setActive((strtoupper($languageTranslation) == strtoupper($lang)) ? 1 : 0);
                $menu->addMenuItem($item);
            }
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);

        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('list', ['category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']]))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $saveButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setDataAttributes([
                'action' => 'save'
            ])
            ->setIcon($iconFactory->getIcon('actions-save', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Save');
        $buttonBar->addButton($saveButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if (!empty($this->pageData)) {
            $this->moduleTemplate->assign('pageData', $this->pageData);
        }

        if (false && !in_array($languageTranslation, $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            $this->moduleTemplate->assign('is_empty', true);
        } else {
            $originalLanguageFilePath = $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['path'];
            $this->languageService->init($languageTranslation);
            $data = $this->languageService->includeLLFile($originalLanguageFilePath);

            if (empty($data[$languageTranslation])) {
                $data[$languageTranslation] = $data['default'];
            }

            if (\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'useCategorization')) {
                $output = [];
                foreach ($data[$languageTranslation] as $key => $value) {
                    $this->setCategorizatedData($output, $key, $value, $key);
                }
                $this->moduleTemplate->assign('data', $output);
                $this->moduleTemplate->assign('isCategorized', true);
            } else {
                $this->moduleTemplate->assign('data', $data);
            }

            $this->moduleTemplate->assign('langaugeKey', $languageTranslation);
            $this->moduleTemplate->assign('translationKey', $keyTranslation);
            $this->moduleTemplate->assign('category', $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['label'] ?? '');

            $this->moduleTemplate->assign('accessibleLanguages', $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages']);
        }

        return $this->moduleTemplate->renderResponse('Be/Translator/Detail');
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     *
     * Template: Be/Translator/ChooseBackupOrNew
     * Description: Shown from detailAction if user asks for a new translation, but backup is already available
     */
    public function chooseBackupOrNewAction($keyTranslation, $languageTranslation)
    {
        $path = $this->getTranslationPath($languageTranslation, $keyTranslation);

        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('list', ['category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']]))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $this->moduleTemplate->assignMultiple([
            'backupLastEdit' => filemtime($path . $this->backupExtension),
            'keyTranslation' => $keyTranslation,
            'languageTranslation' => $languageTranslation
        ]);

        return $this->moduleTemplate->renderResponse('Be/Translator/ChooseBackupOrNew');
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     * @param string $format
     *
     * Description: Export current translation file into specific format
     */
    public function downloadAction($keyTranslation, $languageTranslation, $format)
    {
        $originalLanguageFilePath = $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['path'];
        $this->languageService->init($languageTranslation);
        $data = $this->languageService->includeLLFile($originalLanguageFilePath);
        $downloadFilename = explode('/', $originalLanguageFilePath);
        $downloadFilename = explode('.', $downloadFilename[count($downloadFilename) - 1]);
        unset($downloadFilename[count($downloadFilename) - 1]);
        $downloadFilename = implode('.', $downloadFilename);
        if ($languageTranslation != 'en' && $languageTranslation != 'default') {
            $downloadFilename = $languageTranslation . '.' . $downloadFilename;
        }

        switch ($format) {
            case 'xls':
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();

                $iterator = 0;
                foreach ($data[$languageTranslation] as $key => $value) {
                    $iterator++;
                    $sheet->setCellValue("A{$iterator}", $key);
                    $sheet->setCellValue("B{$iterator}", $value[0]['source']);
                    $sheet->setCellValue("C{$iterator}", $value[0]['target']);
                }
                $writer = new Xlsx($spreadsheet);

                $downloadFilename = $downloadFilename . '.xlsx';
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                $writer->save('php://output');
                exit();
                break;
            case 'csv':
                $realData = [];
                foreach ($data[$languageTranslation] as $key => $value) {
                    $tempData = [];
                    $tempData[] = $key;
                    $tempData[] = $value[0]['source'];
                    $tempData[] = $value[0]['target'];
                    $realData[] = \TYPO3\CMS\Core\Utility\CsvUtility::csvValues($tempData);
                }

                $downloadFilename = $downloadFilename . '.csv';
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                echo implode(PHP_EOL, $realData);
                exit();
                break;
            case 'json':
                $downloadFilename = $downloadFilename . '.json';
                header('Content-Description: File Transfer');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                header('Content-Transfer-Encoding: binary');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                echo json_encode($data[$languageTranslation], JSON_PRETTY_PRINT);
                exit();
                break;
            case 'xlf':
                $absolutePath = $this->getTranslationPath($languageTranslation, $keyTranslation);
                $downloadFilename = $downloadFilename . '.xlf';

                if (file_exists($absolutePath)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($absolutePath)); //Absolute URL
                    ob_clean();
                    flush();
                    readfile($absolutePath); //Absolute URL
                }
                exit();
        }
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     *
     * Description: Revert the Backuped file and redirect into detailAction
     */
    public function revertBackupAction($keyTranslation, $languageTranslation)
    {
        $path = $this->getTranslationPath($languageTranslation, $keyTranslation);
        rename($path . $this->backupExtension, $path);
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        return $this->redirect('detail', null,null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     *
     * Description: Posted file imports new translation strings, then it's redirected to the detailAction.
     */
    public function importAction($keyTranslation, $languageTranslation)
    {
        $file = false;
        if ($this->request->getUploadedFiles()) {
            $file = $this->request->getUploadedFiles()['file'] ?? false;
        }

        if (!$file) {
            return $this->redirect('detail',  null, null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
        }

        $extension = explode('.', $file->getClientFilename());
        $extension = strtolower($extension[count($extension) - 1]);
        $content = (string) $file->getStream();
        $data = [];

        switch($extension) {
            case 'xlf':
                $xlfService = GeneralUtility::makeInstance(XlfService::class);
                $data = $xlfService->xlfToData($content, ['default', $languageTranslation]);
                break;
        }

        if (!empty($data)) {
            return $this->redirect('save', null, null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation, 'data' => $data, 'redirectToDetail' => true]);
        }

        return $this->redirect('detail', null, null, [
            'keyTranslation' => $keyTranslation,
            'languageTranslation' => $languageTranslation,
            'emptyImport' => true
        ]);
    }

    /**
     * @\TYPO3\CMS\Extbase\Annotation\IgnoreValidation("data")
     *
     * @param string $keyTranslation
     * @param string $languageTranslation
     * @param array $data
     *
     * Description: Main save action of the static strings.
     */
    public function saveAction($keyTranslation, $languageTranslation, $data = null)
    {
        if (empty($data) && $this->request->hasArgument('data')) {
            $data = $this->request->getArgument('data');
        }
        if (empty($data)) {
            $content = file_get_contents('php://input');
            $content = json_decode($content);
            $string = '';
            $temp = [];
            foreach ($content as $key => $value) {
                $key = str_replace(']', '', $key);
                $keys = explode('[', $key);

                $this->multidimensionalArray($value, $temp, $keys);
            }
            if (!empty($temp)) {
                $data = $temp;
            }
        }

        if (empty($data)) {
            if ($this->request->hasArgument('redirectToDetail') && $this->request->getArgument('redirectToDetail')) {
                return $this->redirect('detail', null,null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
            }
            echo json_encode(['success' => 0]);
            die();
        }

        $xlfFileExport = $this->dataToXlf($keyTranslation, $languageTranslation, $data);
        $path = $this->getTranslationPath($languageTranslation, $keyTranslation);
        file_put_contents($path, $xlfFileExport);

        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        if ($this->request->hasArgument('redirectToDetail') && $this->request->getArgument('redirectToDetail')) {
            return $this->redirect('detail', null,null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
        }
        echo json_encode(['success' => 1]);
        die();
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     *
     * Description: remove current translation by renaming the file into backup
     */
    public function removeAction($keyTranslation, $languageTranslation)
    {
        $path = $this->getTranslationPath($languageTranslation, $keyTranslation);

        if (file_exists($path)) {
            rename($path, $path . $this->backupExtension);
        }
        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        return $this->redirect('list', null, null, [
            'category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']
        ]);
    }

    // DATABASE RELATED ACTIONS
    /**
     * Template: Be/Translator/PageContentExport
     * Description: Initialization of page export.
     * It's triggered by docheader tool bar, page tree context menu or from indexAction over action selector
     */
    public function pageContentExportAction()
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);

        $this->indexMenu();
        $currentPage = '';
        if ($this->request->hasArgument('page')) {
            $currentPage = $this->request->getArgument('page');
        }

        $this->moduleTemplate->assign('currentPage', $currentPage);
        $this->moduleTemplate->assign('languages', $this->listOfPossibleLanguages);
        $entry = $databaseEntriesService->getCompleteCleanRow('pages', (int) $currentPage);
        $sourceLanguageUid = $entry['sys_language_uid'] ?? 0;
        $this->moduleTemplate->assign('currentLanguageUid', $sourceLanguageUid);

        return $this->moduleTemplate->renderResponse('Be/Translator/PageContentExport');
    }

    /**
     * Template: Be/Translator/Database
     * Description: Initial action for database exports
     */
    public function databaseAction()
    {
        $this->indexMenu();

        $this->moduleTemplate->assign('languages', $this->listOfPossibleLanguages);

        $tables = [];
        foreach ($GLOBALS['TCA'] as $tableName => $data) {
            if (!empty($data['ctrl']['languageField'])) {
                $tables[] = [
                    'tableName' => $tableName,
                    'tableTitle' => !empty($data['ctrl']['title']) ? $data['ctrl']['title'] : $tableName,
                ];
            }
        }

        $this->moduleTemplate->assign('tables', $tables);

        return $this->moduleTemplate->renderResponse('Be/Translator/Database');
    }

    /**
     * @param array $tables
     * @param string $storages
     * Description: Export of database rows
     */
    public function databaseExportAction(array $tables, string $storages)
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);

        if (trim($storages) == '') {
            $storages = -1;
        }
        $storages = GeneralUtility::trimExplode(',', $storages);
        if ($this->request->hasArgument('subpages') && $this->request->getArgument('subpages') == 1) {
            $tempStorage = $storages;
            foreach ($tempStorage as $storage) {
                $databaseEntriesService->addAllSubpages((int) $storage, $storages);
            }
        }

        $saveToZip = true;

        $defaultLanguage = 1;
        $sourceLangauge = 0;
        $targetLanguage = 'de';
        //set to true, because it's the default value in $databaseEntriesService->exportDatabaseRowToXlf()
        $enableTranslatedData = true;
        if ($this->request->hasArgument('language')) {
            $targetLanguage = $this->request->getArgument('language');
        }
        $source = 'en';
        if ($this->request->hasArgument('source-language')) {
            $source = $this->request->getArgument('source-language');
        }

        if ($this->request->hasArgument('ignoreExport')) {
            $databaseEntriesService->setIgnoreExportFields((bool) $this->request->getArgument('ignoreExport'));
        }

        if ($saveToZip) {
            $zipFolder = Environment::getVarPath() . '/translation/';
            if (!file_exists($zipFolder)) {
                mkdir($zipFolder);
            }
            $zipPath = $zipFolder . 'translation.zip';
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE)!==TRUE) {
                exit("cannot open <$zipPath>\n");
            }
        }

        $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);
        $output = '';
        foreach ($storages as $storage) {
            foreach($tables as $tablename) {
                $contentRows = $databaseEntriesService->getAllCompleteteRowsForPid($tablename, (int) $storage, $sourceLangauge,false);
                if(empty($contentRows)) {
                    $output .= ' No entries in '.$tablename.' for pid '.$storage;
                } else {
                    foreach ($contentRows as $contentRowUid => $contentRow) {
                        if ($saveToZip) {
                            $output = '';
                        }
                        $cleanRow = $databaseEntriesService->getExportFields($tablename, $contentRow);
                        $output .= $databaseEntriesService->exportDatabaseRowToXlf($contentRowUid, $cleanRow, $targetLanguage, $tablename, $enableTranslatedData, $source);

                        if ($saveToZip) {
                            if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
                                $zip->addFromString("$tablename-{$contentRow['pid']}-{$contentRow['uid']}.xlf", $output, \ZipArchive::FL_OVERWRITE);
                            } else {
                                $zip->addFromString("$tablename-{$contentRow['pid']}-{$contentRow['uid']}.xlf", $output);
                            }
                        }
                    }
                }
            }
        }

        if ($saveToZip) {
            $zip->close();

            //if no records are found, the zip file would be empty, which is not valid
            //zip file is automatically deleted by ZipArchive, fallback to printing the output
            if(file_exists($zipPath)) {
                header('Pragma: public');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Cache-Control: private', false);
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . basename($zipPath) . '";');
                header('Content-Transfer-Encoding: binary');
                header('Content-Length: ' . filesize($zipPath));
                echo file_get_contents($zipPath);
                \Hyperdigital\HdTranslator\Services\FileService::rmdir($zipFolder);
            }else {
                echo $output;
            }

        } else {
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Cache-Control: private', false);
            header('Content-type: text/xml');
            header('Content-Disposition: attachment; filename="page-'.$storage.'.xlf"');
            echo $output;
        }
        die();
    }

    /**
     * Description: Database import initial screen
     */
    public function databaseImportIndexAction()
    {
        $this->indexMenu();

        return $this->moduleTemplate->renderResponse('Be/Translator/DatabaseImportIndex');
    }

    /**
     * Description: Submitted file for database import
     */
    public function databaseImportAction()
    {
        $errors = [];
        $files = false;
        if ($this->request->getUploadedFiles()) {
            $files = $this->request->getUploadedFiles()['files'] ?? false;
        }

        if (!$files) {
            $errors[] = 'No file uploaded';
        } else {
            $targetLanguage = 1;
            if ($this->request->hasArgument('targetLanguageUid')) {
                $targetLanguage = (int) $this->request->getArgument('targetLanguageUid');
            }
            $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);
            $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);
            $sourcePart = 'target';
            if ($this->request->hasArgument('translationSource')) {
                $sourcePart = $this->request->getArgument('translationSource');
            }

            foreach($files as $file) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);

                $extension = explode('.', $file->getClientFilename());
                $extension = strtolower($extension[count($extension) - 1]);

                switch($extension){
                    case 'xlf':
                        // XLF
                        $data = (string) $file->getStream();
                        $data = $xlfService->xlfToData($data, [], $sourcePart);
                        $databaseEntriesService->importIntoDatabase($data, $targetLanguage);
                        break;
                    case 'zip':
                        $zipFolder = Environment::getVarPath() . '/translation/';
                        if (!file_exists($zipFolder)) {
                            mkdir($zipFolder);
                        }
                        $file->moveTo($zipFolder.$file->getClientFilename());
                        // ZIP of packed translations
                        $zip = new \ZipArchive();
                        $zip->open($zipFolder.$file->getClientFilename());
                        for($i = 0; $i < $zip->numFiles; $i++) {
                            $data = $zip->getFromIndex($i);
                            $data = $xlfService->xlfToData($data, [], $sourcePart);
                            $databaseEntriesService->importIntoDatabase($data, $targetLanguage);
                        }
                        break;
                }
            }
        }
        if (!empty($zipFolder) && file_exists($zipFolder)) {
            \Hyperdigital\HdTranslator\Services\FileService::rmdir($zipFolder);
        }

        $this->moduleTemplate->assignMultiple([
            'actions' => [
                'failsMessages' => \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$importStats['failsMessages'],
                'inserted' => \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$importStats['inserts'],
                'updated' => \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$importStats['updates'],
                'fails' => \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$importStats['fails'],
            ]
        ]);

        return $this->moduleTemplate->renderResponse('Be/Translator/DatabaseImport');
    }



    ///////////////////////////////////
    protected function indexMenu()
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('hd_translator_index');

        // Static strings
        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.index', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('index', null))
            ->setActive('index' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.pageContentExport', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('pageContentExport', null))
            ->setActive('pageContentExport' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.database', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('database', null))
            ->setActive('database' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        if ($this->request->getControllerActionName() == 'exportTableRowIndex') {
            $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.exportTableRowIndex', 'hd_translator'))
                ->setHref($uriBuilder->reset()->uriFor('exportTableRowIndex', null))
                ->setActive('exportTableRowIndex' == $this->request->getControllerActionName() ? 1 : 0);
            $menu->addMenuItem($item);
        }

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.databaseImportIndex', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('databaseImportIndex', null))
            ->setActive('databaseImportIndex' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        if (!empty($this->deeplApiKey)) {
            $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.deeplTranslations', 'hd_translator'))
                ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList', null))
                ->setActive(in_array($this->request->getControllerActionName(), ['deeplTranslationsList', 'deeplSyncLanguages', 'deeplTranslationLanguage', 'deeplShowTranslationsOfOriginal', 'deeplOriginalSources']) ? 1 : 0);
            $menu->addMenuItem($item);
        }


        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }


    /**
     * @param string $tablename
     * @param int $rowUid
     */
    public function exportTableRowIndexAction(string $tablename, int $rowUid)
    {
        $this->indexMenu();

        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);
        $row = $databaseEntriesService->getCompleteRow($tablename, $rowUid);

        $label = $databaseEntriesService->getLabel($tablename, $row);

        $this->moduleTemplate->assign('tablename', $tablename);
        $this->moduleTemplate->assign('rowUid', $rowUid);
        $this->moduleTemplate->assign('label', $label);
        $this->moduleTemplate->assign('fields', $databaseEntriesService->getExportFields($tablename, $row));
        $this->moduleTemplate->assign('languages', $this->listOfPossibleLanguages);
        $this->moduleTemplate->assign('rowType', \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$rowType);
        $this->moduleTemplate->assign('rowTypeCouldBe', \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$rowTypeCouldBe);

        $this->moduleTemplate->setContent($this->moduleTemplate->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());
    }

    /**
     * @param string $tablename
     * @param int $rowUid
     */
    public function exportTableRowExportAction(string $tablename, int $rowUid)
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);
        $row = $databaseEntriesService->getCompleteRow($tablename, $rowUid);
        $label = $databaseEntriesService->getFilenameFromLabel($tablename, $row);

        $cleanRow = $databaseEntriesService->getExportFields($tablename, $row);
        $output = $databaseEntriesService->exportDatabaseRowToXlf($rowUid, $cleanRow, $this->request->getArgument('language'), $tablename, true, $this->request->getArgument('source'));
        header('Content-type: text/xml');
        header('Content-Disposition: attachment; filename="'.$label.'.xlf"');
        echo $output;
        die();
    }

    /**
     * @param string $storages
     */
    public function pageContentExportProccessAction(string $storages)
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);

        if ($storages == '') {
            $storages = -1;
        }
        $storages = GeneralUtility::trimExplode(',', $storages);
        if ($this->request->hasArgument('subpages') && $this->request->getArgument('subpages') == 1) {
            $tempStorage = $storages;
            foreach ($tempStorage as $storage) {
//                $databaseEntriesService->addAllSubpages((int) $storage, $storages, $this->request->getArgument('pageTypes'));
                $databaseEntriesService->addAllSubpages((int) $storage, $storages);
            }
        }

        if ($this->request->hasArgument('ignoreExport')) {
            $databaseEntriesService->setIgnoreExportFields((bool) $this->request->getArgument('ignoreExport'));
        }

        $sourceLanguage = 0;
        if ($this->request->hasArgument('source')) {
            $sourceLanguage = $this->request->getArgument('source');
        }

        $saveToZip = false;
        if (count($storages) > 1) {
            $saveToZip = true;
        }
        $defaultLanguage = 1;
        $targetLanguage = 'de';
        $source = 'en';
        if ($this->request->hasArgument('language')) {
            $targetLanguage = $this->request->getArgument('language');
        }
        if ($this->request->hasArgument('source-language')) {
            $source = $this->request->getArgument('source-language');
        }

        if ($saveToZip) {
            $zipFolder = Environment::getVarPath() . '/translation/';
            if (!file_exists($zipFolder)) {
                mkdir($zipFolder);
            }
            $zipPath = $zipFolder . 'translation.zip';
            if (file_exists($zipPath)) {
                unlink($zipPath);
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE)!==TRUE) {
                exit("cannot open <$zipPath>\n");
            }
        }


        foreach ($storages as $storage) {
            $contentArray = $databaseEntriesService->getCompleteContentForPage((int)$storage, (int) $sourceLanguage, $targetLanguage);

            if (!empty($contentArray)) {
                $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);
                $output = $xlfService->dataToXlf($contentArray, $targetLanguage, $source);

                if ($saveToZip) {
                    $zip->addFromString("page-{$storage}.xlf", $output);
                }
            }
        }

        if ($saveToZip) {
            $zip->close();
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Cache-Control: private', false);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($zipPath) . '";');
            header('Content-Transfer-Encoding: binary');
            header('Content-Length: ' . filesize($zipPath));

            echo file_get_contents($zipPath);
            \Hyperdigital\HdTranslator\Services\FileService::rmdir($zipFolder);
        } else {
            header('Pragma: public');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Cache-Control: private', false);
            header('Content-type: text/xml');
            header('Content-Disposition: attachment; filename="page-'.$storage.'.xlf"');
            echo $output;
        }
        die();
    }

    public function databaseTableFieldsAction()
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('database'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $tables = [];
        if (!$this->request->hasArgument('tables')) {
            $errors[] = 'Tables is missing';
        } else {
            $tables = $this->request->getArgument('tables');
        }

        $fields = [];
        $disabledFields = [];
        $disabledFields[] = 't3_origuid';
        foreach ($tables as $table) {
            $targetUidField = 'l10n_parent';
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                $targetUidField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
            }
            $langaugeField = 'sys_language_uid';
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
                $langaugeField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
            }
            $disabledFields[] = $targetUidField;
            $disabledFields[] = $langaugeField;

            foreach ($GLOBALS['TCA'][$table]['columns'] as $key => $columnData) {
                if (!in_array($key, $disabledFields)) {
                    $fields[$table][] = [
                        'fieldName' => $key,
                    ];
                }
            }
        }

        $allowedLanguages = [];
        $allowedLanguages[] = [
            'uid' => 0,
            'title' => 'Default'
        ];

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_language')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder->select('*')->from('sys_language')->execute();
        while ($row = $result->fetch()) {
            $allowedLanguages[] = $row;
        }


        $this->moduleTemplate->assign('allowedLanguages', $allowedLanguages);
        $this->moduleTemplate->assign('tables', $fields);

        $this->moduleTemplate->setContent($this->moduleTemplate->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());;
    }

    public function getAllPagesFromRoot($roots, &$return)
    {
        $roots = explode(',', strval($roots));
        foreach ($roots as $root) {
            $root = (int) $root;
            if (!in_array($root, $return)) {
                $return[] = $root;
            }

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select('uid')->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $root)
                )
                ->execute();
            while($row = $result->fetch()) {
                $this->getAllPagesFromRoot($row['uid'], $return);
            }
        }
    }

    protected function idToDatabaseNames(&$retrun, $id, $value)
    {
        $parts = explode('.',$id);
        $languageUid = $parts[0];
        $table = $parts[1];
        $uid = $parts[2];

        unset($parts[0]);
        unset($parts[1]);
        unset($parts[2]);

        $field = implode('.', $parts);
        $retrun[$languageUid][$table][$uid][$field] = $value;
    }

    protected function keysToSubarray($fieldData, $valueToInsert, &$originalData)
    {
        $key = key($fieldData);
        $keyLabel = reset($fieldData);

        if (count($fieldData) > 0) {
            unset($fieldData[$key]);
            if (!is_array($originalData)) {
                $originalData = GeneralUtility::xml2array($originalData);
            }

            if (!empty($originalData['data'])) {
                foreach ($originalData['data'] as $key => $dataSheet) {
                    if (!empty($dataSheet['lDEF'])) {
                        foreach ($dataSheet['lDEF'] as $fieldKey => $value) {
                            $fieldDataTemp = $fieldData;
                            $keysCount = count(explode('.', $fieldKey));
                            $newKyes = [];
                            for ($i = 0; $i < $keysCount; $i++) {
                                $tempKey = key($fieldDataTemp);
                                $newKyes[] = reset($fieldDataTemp);
                                unset($fieldDataTemp[$tempKey]);
                            }

                            $newKyes = implode('.', $newKyes);
                            if ($newKyes == $fieldKey) {
                                if (empty($fieldDataTemp)) {
                                    $originalData['data'][$key]['lDEF'][$fieldKey]['vDEF'] = $valueToInsert;
                                    break 2;
                                } else {
                                    if (key($value) == 'el') {
                                        // current key - $originalData['data'][$key]['lDEF'][$fieldKey]['el']
                                        $tempKey = key($fieldDataTemp);
                                        $elementHash = reset($fieldDataTemp);
                                        unset($fieldDataTemp[$tempKey]);
                                        // current key - $originalData['data'][$key]['lDEF'][$fieldKey]['el'][$elementHash]
                                        $tempKey = key($fieldDataTemp);
                                        $elementKey = reset($fieldDataTemp);
                                        unset($fieldDataTemp[$tempKey]);
                                        // current key - $originalData['data'][$key]['lDEF'][$fieldKey]['el'][$elementHash][$elementKey]['el']

                                        $finalKey = implode('.', $fieldDataTemp);
                                        $originalData['data'][$key]['lDEF'][$fieldKey]['el'][$elementHash][$elementKey]['el'][$finalKey]['vDEF'] = $valueToInsert;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }
            }

        }


        return $originalData;


//            return array_merge_recursive([],
//                [
//                    $keyLabel => [
//                        'data' => [
//                            'options' => [
//                                'lDEF' => $this->asociativeKeyMap($fieldData, ['vDEF' => strval($value)], $originalData['data']['options']['lDEF'])
//                            ]
//                            ]
//                        ]
//                    ]
//            );
//        }

//        return $keyLabel = $value;
    }

    protected function asociativeKeyMap($fieldData, $value, $originalData, $ignoreOriginalKey = false)
    {
        $keysAsString = implode('.', $fieldData);

        if (!$ignoreOriginalKey) {
            $newKey = false;
            $newOriginalData = false;
            $keyLength = 0;
            foreach ($originalData as $originalKey => $originalValues) {
                $originalKey = strval($originalKey);
                $keyLength = strlen($originalKey);
                if (substr($keysAsString,0, $keyLength) == $originalKey && ($keyLength == strlen($keysAsString) || substr($keysAsString,$keyLength,1) == '.')) {
                    $newKey = $originalKey;
                    $newOriginalData = $originalValues;
                    break;
                }
            }

            if (!$newKey && count($originalData) == 1 && key($originalData) == 'el') {
                return ['el' => $this->asociativeKeyMap(explode('.', $keysAsString), $value, $originalData['el'], true)];
            }
            if ($keyLength == 0) {
                $keysAsString = '';
                $newKey = implode('.',$fieldData);
            } else {
                $keysAsString = substr($keysAsString, $keyLength + 1);
            }
        }  else {
            $key = key($fieldData);
            $newKey = reset($fieldData);
            unset($fieldData[$key]);
            $keysAsString = implode('.', $fieldData);

            $key = key($originalData);
            $newOriginalData = $originalData[$key];
        }
        $keysAsString = strval($keysAsString);
        if ($newOriginalData && strlen($keysAsString) > 0) {
            return [$newKey => $this->asociativeKeyMap(explode('.', $keysAsString), $value, $newOriginalData)];
        } else {
            if (!$newKey) {
                $newKey = $fieldData[0];
            }
            return [$newKey => $value];
        }
    }

    public function importItself($syncArray)
    {
        if (!empty($syncArray)) {
            foreach ($syncArray as $languageUid => $tables) {
                foreach ($tables as $table => $uids) {
                    $fieldsToBeIgnored = [];
                    $targetUidField = 'uid';
                    $fieldsToBeIgnored[] = 'uid';
                    if ($languageUid != 0) {
                        $targetUidField = 'l10n_parent';
                        if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                            $targetUidField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
                        }
                    }
                    $fieldsToBeIgnored[] = $targetUidField;
                    $langaugeField = 'sys_language_uid';
                    if (!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
                        $langaugeField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
                    }
                    $fieldsToBeIgnored[] = $langaugeField;

                    foreach ($uids as $uid => $fields) {
                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                        $tempQuery = $queryBuilder->select('uid')->from($table);
                        $tempQuery->where(
                            $queryBuilder->expr()->eq($langaugeField, $languageUid),
                            $queryBuilder->expr()->eq($targetUidField, $uid)
                        );

                        $result = $tempQuery->execute()->fetch();

                        //ORIGINAL
                        if (empty($this->originalData[$table][$uid])) {
                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            $tempQuery = $queryBuilder->select('*')->from($table);
                            $tempQuery->where(
                                $queryBuilder->expr()->eq('uid', $uid)
                            );

                            $originalData = $tempQuery->execute()->fetch();
                            $this->superOriginalData[$table][$uid] = $this->originalData[$table][$uid] = $originalData;
                        }

                        if (!empty($result['uid'])) {
                            // ONLY UPDATE QUERY
                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            $tempQueryUpdate = $queryBuilder
                                ->update($table)
                                ->where(
                                    $queryBuilder->expr()->eq('uid', $result['uid'])
                                );

                            $fieldsToSync = [];
                            $fieldNames = [];

                            foreach ($fields as $field => $value) {
                                if (!in_array($field, $fieldsToBeIgnored)) {
                                    $fieldData = explode('.', $field);
                                    if (!empty($fieldData[1])) {
                                        $newSettings = $this->keysToSubarray($fieldData, $value, $this->originalData[$table][$uid][$fieldData[0]]);
                                        $fieldsToSync[$fieldData[0]] = $newSettings;
                                    } else {
                                        $fieldNames[] = $field;
                                        $tempQueryUpdate->set($field, $value);
                                    }
                                }
                            }

                            foreach ($fieldsToSync as $tableColumn => $data) {
                                $fieldNames[] = $tableColumn;
                                $type = $GLOBALS['TCA'][$table]['columns'][$tableColumn]['config']['type'];

                                if ($type == 'flex') {
                                    $flexFormTools = new FlexFormTools();
                                    $flexFormString = $flexFormTools->flexArray2Xml($data, true);
                                    $fieldsToSync[$tableColumn] = $flexFormString;
                                    $tempQueryUpdate->set($tableColumn, $flexFormString);
                                }
                            }
                            if (!empty($this->originalData[$table][$uid]['sorting']) && empty($fieldsToSync['sorting'])) {
                                $tempQueryUpdate->set('sorting', $this->originalData[$table][$uid]['sorting']);
                            }
                            if (!empty($this->originalData[$table][$uid]['doktype']) && empty($fieldsToSync['doktype'])) {
                                $tempQueryUpdate->set('doktype', $this->originalData[$table][$uid]['doktype']);
                            }
                            if (!empty($this->originalData[$table][$uid]['CType']) && empty($fieldsToSync['CType'])) {
                                $tempQueryUpdate->set('CType', $this->originalData[$table][$uid]['CType']);
                            }
                            // Always inherit colPos from the parent record. Preference order:
                            // 1) l18n_parent (as requested), 2) l10n_parent, 3) l10n_source, 4) fallback to original uid
                            $transRowQb = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                            $transRowQb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            $transRow = $transRowQb
                                ->select('l18n_parent', 'l10n_parent', 'l10n_source')
                                ->from($table)
                                ->where(
                                    $transRowQb->expr()->eq('uid', $result['uid'])
                                )
                                ->execute()
                                ->fetch();
                            $sourceUid = null;
                            if (!empty($transRow['l18n_parent'])) {
                                $sourceUid = (int)$transRow['l18n_parent'];
                            } elseif (!empty($transRow['l10n_parent'])) {
                                $sourceUid = (int)$transRow['l10n_parent'];
                            } elseif (!empty($transRow['l10n_source'])) {
                                $sourceUid = (int)$transRow['l10n_source'];
                            } else {
                                $sourceUid = (int)$uid;
                            }

                            // Ensure originalData is hydrated for the resolved source uid
                            if (empty($this->originalData[$table][$sourceUid])) {
                                $srcQb = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                                $srcQb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                                $srcRow = $srcQb
                                    ->select('*')
                                    ->from($table)
                                    ->where(
                                        $srcQb->expr()->eq('uid', $sourceUid)
                                    )
                                    ->execute()
                                    ->fetch();
                                if ($srcRow) {
                                    $this->superOriginalData[$table][$sourceUid] = $this->originalData[$table][$sourceUid] = $srcRow;
                                }
                            }
                            $srcColPos = $this->originalData[$table][$sourceUid]['colPos'] ?? null;

                            if (isset($this->originalData[$table][$sourceUid]['colPos'])) {
                                $tempQueryUpdate->set('colPos', $this->originalData[$table][$sourceUid]['colPos']);
                            }
                            if (!empty($this->originalData[$table][$uid]['list_type']) && empty($fieldsToSync['list_type'])) {
                                $tempQueryUpdate->set('list_type', $this->originalData[$table][$uid]['list_type']);
                            }
                            if (isset($this->originalData[$table][$uid]['l10n_source'])) {
                                $tempQueryUpdate->set('l10n_source', $uid);
                            }

                            $tempQueryUpdate->execute();
                            $output['updated'][] = $table.':'.$result['uid'] .' fields:'.implode(',',$fieldNames);
                        } else {
                            // Insert query
                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();

                            $fieldsToSync = [];
                            $fieldNames = [];
                            $insert = [];
                            foreach ($fields as $field => $value) {
                                $fieldData = explode('.', $field);
                                if (!empty($fieldData[1])) {
                                    $newSettings = $this->keysToSubarray($fieldData, $value, $originalData[$fieldData[0]]);
                                    $fieldsToSync = array_merge_recursive($fieldsToSync, $newSettings);
                                } else {
                                    $fieldNames[] = $field;
                                    $insert[$field] = $value;
                                }
                            }

                            foreach ($fieldsToSync as $tableColumn => $data) {
                                $fieldNames[] = $tableColumn;
                                $type = $GLOBALS['TCA'][$table]['columns'][$tableColumn]['config']['type'];

                                if ($type == 'flex') {
                                    $flexFormTools = new FlexFormTools();
                                    $flexFormString = $flexFormTools->flexArray2Xml($data, true);

                                    $insert[$tableColumn] = $flexFormString;
                                }
                            }

                            if (!empty($insert)) {
                                $insert[$langaugeField] = $languageUid;
                                $insert[$targetUidField] = $uid;

                                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
                                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                                $tempQuery = $queryBuilder->select('*')->from($table);
                                $tempQuery->where(
                                    $queryBuilder->expr()->eq('uid', $uid)
                                );
                                $originalData = $tempQuery->execute()->fetch();
                                if ($originalData) {
                                    $insert['pid'] = $originalData['pid'];
                                    $insert['crdate'] = time();
                                    $insert['tstamp'] = time();

                                    if (!empty($originalData['sorting']) && empty($insert['sorting'])) {
                                        $insert['sorting'] = $originalData['sorting'];
                                    }
                                    if (!empty($originalData['doktype']) && empty($insert['doktype'])) {
                                        $insert['doktype'] = $originalData['doktype'];
                                    }
                                    if (!empty($originalData['CType']) && empty($insert['CType'])) {
                                        $insert['CType'] = $originalData['CType'];
                                    }
                                    // Always inherit colPos from the original record on insert as well
                                    if (array_key_exists('colPos', $originalData)) {
                                        $insert['colPos'] = $originalData['colPos'];
                                    }   
                                    if (!empty($originalData['list_type']) && empty($insert['list_type'])) {
                                        $insert['list_type'] = $originalData['list_type'];
                                    }
                                    if (isset($originalData['l10n_source'])) {
                                        $insert['l10n_source'] = $uid;
                                    }
                                }

                                $queryBuilder
                                    ->insert($table)
                                    ->values($insert)
                                    ->execute();
                                $lastUid = $queryBuilder->getConnection()->lastInsertId();
                                $output['inserted'][] = $table.':'.$lastUid .' fields:'.implode(',',$fieldNames);
                            }
                        }
                    }
                }
            }
        }

        $this->moduleTemplate->assign('actions', $output);
    }

    public function importXlfFile($content)
    {
        $output = [];
        $doc = new \DOMDocument();
        $doc->loadXML($content, LIBXML_PARSEHUGE );
        $syncArray = [];
        foreach($doc->getElementsByTagName('trans-unit') as $unit) {
            $id = $unit->getAttribute('id');
            $value = $unit->getElementsByTagName('target')->item(0)->nodeValue;

            $this->idToDatabaseNames($syncArray, $id, $value);
        }

        $this->importItself($syncArray);
    }

    public function importXmlFile($content)
    {
        $output = [];
        $doc = new \DOMDocument();
        $doc->loadXML($content, LIBXML_PARSEHUGE );
        $syncArray = [];
        foreach($doc->getElementsByTagName('data') as $unit) {
            $id = $unit->getAttribute('key');
            $value = $unit->nodeValue;

            $this->idToDatabaseNames($syncArray, $id, $value);
        }

        $this->importItself($syncArray);
    }


    protected function exportDatabaseToXlm($data, $targetLanguageLetters)
    {
        $output = $this->dataToXml($data, $targetLanguageLetters);

        header('Content-type: text/xml');
        header('Content-Disposition: attachment; filename="temp.xml"');
        echo $output;
    }

    protected function databaseFlexformDataToTranslationArray(&$data, $lastKey, $targetLanguageLetters, $flexformDataOriginal, $flexformDataTarget)
    {
        if (is_array($flexformDataOriginal)) {
            foreach ($flexformDataOriginal as $key => $value) {
                $target = null;
                if ($flexformDataTarget[$key]) {
                    $target = $flexformDataTarget[$key];
                }
                $this->databaseFlexformDataToTranslationArray($data, $lastKey.'.'.$key, $targetLanguageLetters, $value, $target);
            }
        } else {
            if (!empty($flexformDataOriginal) || !empty($flexformDataTarget) ) {
                $data[$lastKey]['default'] = strval($flexformDataOriginal);
                $data[$lastKey][$targetLanguageLetters] = strval(($flexformDataTarget) ? $flexformDataTarget : $flexformDataOriginal);
            }
        }
    }


    protected function exportDatabaseToXlf($data, $sourceLanguage, $targetLanguage)
    {
        $output = $this->dataToXlf('database', $targetLanguage, $data, $sourceLanguage);

        header('Content-type: text/xml');
        header('Content-Disposition: attachment; filename="temp.xlf"');
        echo $output;
    }

    public function syncLocallangsAction()
    {

        $listOfExtensions = $this->listUtility->getAvailableExtensions();
        $typo3Version = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Information\Typo3Version::class)->getVersion();

        foreach ($listOfExtensions as $key => $extConf) {
            if (version_compare($typo3Version, '11.0.0', '<')) {
                $extensionModelUtility = $this->objectManager->get(ExtensionModelUtility::class);
                $extConfig = $extensionModelUtility->mapExtensionArrayToModel($extConf);
            } else {
                $extConfig = Extension::createFromExtensionArray($extConf);
            }

            $baseFolder = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName('EXT:' . $key . '/' . $this->relativePathToLangFilesInExt);
            if ($baseFolder) {
                $this->getAllLangFilesFromPath($extConfig, $baseFolder, 'EXT:' . $key . '/' . $this->relativePathToLangFilesInExt, $key);
            }
            $baseFolder = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName('EXT:' . $key . '/' . $this->relativePathToLangFilesInExtContentBlocks);
            if ($baseFolder) {
                $this->getAllLangFilesFromPath($extConfig, $baseFolder, 'EXT:' . $key . '/' . $this->relativePathToLangFilesInExtContentBlocks, $key, true);
            }
        }

        file_put_contents($this->storage . $this->conigurationFile, "<?php\n" . '$GLOBALS["TYPO3_CONF_VARS"]["translator"] = ' . var_export($this->langFiles, true) . ';');
        return $this->redirect('index');
    }

    protected function getAllLangFilesFromPath($extConfig, $path, $extPath, $key, $contentBlocks = false)
    {
        if (file_exists($path)) {
            $files = scandir($path);
            if ($files) {
                foreach ($files as $filename) {
                    if ($filename == '.' || $filename == '..') {
                        continue;
                    }

                    if (is_dir($path . '/' . $filename)) {
                        $this->getAllLangFilesFromPath($extConfig, $path . '/' . $filename, $extPath . '/' . $filename, $key, $contentBlocks);
                    } else {
                        $languageExt = explode('.', $filename);
                        $languageExt = $languageExt[count($languageExt) - 1];

                        if ($languageExt == 'xlf') {
                            // Check if it's not default language
                            $languagePrefix = explode('.', $filename)[0];
                            // The language can be also with sublevel like pt-BR
                            $languagePrefix = explode('-', $languagePrefix)[0];
                            if (in_array($languagePrefix, array_keys($this->listOfPossibleLanguages))) {
                                continue;
                            }

                            $label = $extConfig->getExtensionKey();
                            if ($extConfig->getTitle()) {
                                $label = $extConfig->getTitle();
                            }

                            // File is stored in ContentBlocks/ContentElements/XX/language/labels.xlf and we need to get XX
                            if ($contentBlocks) {
                                $pathParts = explode('/', $path);
                                while ($pathParts[count($pathParts) - 1] != 'language') {
                                    unset($pathParts[count($pathParts) - 1]);
                                }
                                if (!empty($pathParts)) {
                                    $label .= ' - ' . $pathParts[count($pathParts) - 2];
                                }
                            }

                            if ($filename != $this->defaultFilename) {
                                $label .= ': ' . $this->filenameToPrettyPrint($filename);
                            }

                            $this->langFiles[$this->filepathToIdentifier($extPath . '/' . $filename)] = [
                                'label' => $label,
                                'path' => $extPath . '/' . $filename,
                                'category' => $key,
                                'languages' => array_keys($this->listOfPossibleLanguages)
                            ];
                        }
                    }
                }
            }
        }
    }

    protected function filepathToIdentifier($path)
    {
        $path = str_replace([':', '/'], ' ', $path);
        $path = ucwords($path);
        $path = str_replace([' ', '.xlf'], '', $path);

        return $path;
    }

    protected function filenameToPrettyPrint($filename)
    {
        $filename = str_replace('.xlf', '', $filename);
        $filename = \TYPO3\CMS\Core\Utility\GeneralUtility::camelCaseToLowerCaseUnderscored($filename);
        $filenameArray = explode('_', $filename);
        $filename = [];
        foreach ($filenameArray as $part) {
            switch ($part) {
                case 'locallang':
                    break;
                default:
                    $filename[] = $part;
            }
        }
        $filename = ucwords(implode(' ', $filename));

        return $filename;
    }

    /**
     * @param string $sword
     */
    public function searchAction($sword)
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('index'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'])) {
            $data = [];
            $return = [];
            $temp = [];
            $files = scandir($this->storage);

            if ($files) {
                foreach ($files as $file) {
                    if ($file != '.' && $file != '..' && is_file($this->storage . $file)) {
                        $content = strip_tags(file_get_contents($this->storage . $file));
                        if (strpos($content, $sword) !== false) {
                            $data[] = $file;
                        }
                    }
                }
            }
        }

        foreach ($data as $file) {
            $fileData = explode('.', $file);
            if (count($fileData) == 3) {
                $language = $fileData[0];
                $fileIdentifier = $fileData[1];
            } else {
                $language = 'default';
                $fileIdentifier = $fileData[0];
            }

            if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'][$fileIdentifier])) {
                $item = $GLOBALS['TYPO3_CONF_VARS']['translator'][$fileIdentifier];
                if (!empty($temp[$fileIdentifier])) {
                    $item['languages'] = array_merge($return[$fileIdentifier]['languages'], [$language]);
                } else {
                    $item['languages'] = [$language];
                }

                $temp[$fileIdentifier] = $item;

                $category = '-';
                if (!empty($item['category'])) {
                    $category = $item['category'];
                }

                $return[$category][$fileIdentifier] = [
                    'label' => (!empty($item['label'])) ? $item['label'] : $fileIdentifier,
                    'languages' => $item['languages']
                ];
            }
        }

        $this->moduleTemplate->assign('languagesArray', $this->listOfPossibleLanguages);
        $this->moduleTemplate->assign('data', $return);

        $this->moduleTemplate->setContent($this->moduleTemplate->render());
        return $this->htmlResponse($this->moduleTemplate->renderContent());;
    }

    public function setCategorizatedData(&$output, $key, $value, $fullKey)
    {
        $keyArray = explode('.', $key);

        if (!empty($keyArray)) {
            $newKey = $keyArray[0];
            if (count($keyArray) > 1) {
                // another subcategory is needed
                unset($keyArray[0]);
                $this->setCategorizatedData($output[$newKey], implode('.', $keyArray), $value, $fullKey);
            } else {
                $output[$newKey] = [
                    'value' => $value,
                    'fullKey' => $fullKey
                ];
            }
        }
    }

    protected function dataToXlf($keyTranslation, $languageTranslation, $data = null, $sourceLanguage = 'en')
    {
        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;
        $xmlRoot = $domtree->createElement('xliff');
        $xmlRoot->setAttribute('version', '1.2');

        $file = $domtree->createElement('file');
        $file->setAttribute('source-language', $sourceLanguage);
        $file->setAttribute('target-language', $languageTranslation);
        $file->setAttribute('product-name', $keyTranslation);
        $file->setAttribute('original', 'messages');
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('date', date('c'));

        $header = $domtree->createElement('header');
        $file->appendChild($header);

        $body = $domtree->createElement('body');

        foreach ($data as $key => $value) {
            $item = $domtree->createElement('trans-unit');
            $item->setAttribute('id', $key);
            $source = $domtree->createElement('source');

            if ($languageTranslation == 'en' || $languageTranslation == 'default') {
                $valSource = $domtree->createTextNode($value[$languageTranslation]);

                $source->appendChild($valSource);
                $item->appendChild($source);
            } else {
                $target = $domtree->createElement('target');
                $valSource = $domtree->createTextNode((!is_null($value['default'])) ? $value['default'] : $value[$languageTranslation]);
                $valTarget = $domtree->createTextNode($value[$languageTranslation]);

                $source->appendChild($valSource);
                $target->appendChild($valTarget);
                $item->appendChild($source);
                $item->appendChild($target);
            }


            $body->appendChild($item);
        }

        $file->appendChild($body);
        $xmlRoot->appendChild($file);
        $domtree->appendChild($xmlRoot);

        return $domtree->saveXML();
    }

    protected function dataToXml($data, $targetLanguageLetters)
    {
        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;
        $root = $domtree->createElement('TYPO3L10N');
        $head = $domtree->createElement('head');
        $target = $domtree->createElement('t3_targetLang');
        $lang = $domtree->createTextNode($targetLanguageLetters);
        $target->appendChild($lang);
        $root->appendChild($head);
        $page = $domtree->createElement('pageGrp');
        foreach ($data as $key => $value) {
            $dataItem = $domtree->createElement('data');
            $dataItem->setAttribute('key', $key);
            $dataItemValue = $domtree->createTextNode($value[$targetLanguageLetters]);
            $dataItem->appendChild($dataItemValue);
            $page->appendChild($dataItem);
        }
        $root->appendChild($page);
        $domtree->appendChild($root);

        return $domtree->saveXML();
    }

    protected function exec_enabled() {
        $disabled = explode(',', ini_get('disable_functions'));
        return !in_array('exec', $disabled);
    }

    public function deeplTranslationsListAction()
    {
        $this->indexMenu();

        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $languages = $deeplApiService->getAvailableLanguagesWithAmounts();
        $this->moduleTemplate->assign('languages', $languages);
        $this->moduleTemplate->assign('apiKey', $this->deeplApiKey);

        return $this->moduleTemplate->renderResponse('Be/Translator/DeeplTranslationsList');
    }

    public function deeplSyncLanguagesAction()
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $deeplApiService->syncAvailableLanguages();

        return $this->redirect('deeplTranslationsList');
    }

    public function deeplRemoveAllStringsAction(string $language = '')
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $deeplApiService->removeAllTranslations($language);

        return $this->redirect('deeplTranslationsList');
    }

    public function deeplTranslationLanguageAction(string $language = '')
    {
        $this->view->assign('language', $language);

        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplRemoveAllStrings', ['language' => $language]))
            ->setIcon($iconFactory->getIcon('actions-edit-delete', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Remove all strings');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

    //        $this->indexMenu();
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $strings = $deeplApiService->getAllTranslationsForLanguage($language);
        $currentLanguage = $deeplApiService->getLanguageByCode($language);
        $this->moduleTemplate->assign('strings', $strings);
        $this->moduleTemplate->assign('currentLanguage', $currentLanguage);

        return $this->moduleTemplate->renderResponse('Be/Translator/DeeplTranslationLanguage');
    }

    public function deeplShowTranslationsOfOriginalAction(int $string)
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

    //        $this->indexMenu();
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $source = $deeplApiService->getTranslationByUid($string);
        $this->moduleTemplate->assign('source', $source);

        if ($source['original_source']) {
            $translations = $deeplApiService->getTranslationsBySource($source['original_source']);
            $this->moduleTemplate->assign('translations', $translations);
        }

        return $this->moduleTemplate->renderResponse('Be/Translator/DeeplShowTranslationsOfOriginal');
    }

    public function deeplOriginalSourcesAction()
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList'))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', Icon::SIZE_SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

    //        $this->indexMenu();
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class, $this->deeplApiKey);
        $sources = $deeplApiService->getUniqueOriginals();

        $this->moduleTemplate->assign('sources', $sources);

        return $this->moduleTemplate->renderResponse('Be/Translator/DeeplOriginalSources');

    }
}

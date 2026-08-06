<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Controller\Be;

use Hyperdigital\HdTranslator\Services\DeeplApiService;
use Hyperdigital\HdTranslator\Services\XlfService;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Web\Routing\UriBuilder;
use TYPO3\CMS\Extensionmanager\Domain\Model\Extension;
use TYPO3\CMS\Extensionmanager\Utility\ListUtility;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use Psr\Http\Message\ResponseInterface;

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
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
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

        if (!empty($this->storage) && \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($this->storage . $this->conigurationFile)) {
                require $this->storage . $this->conigurationFile;
            } else {
                $this->redirect('syncLocallangs');
            }
        }

        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $this->pageUid = $this->resolveCurrentPageUid();
        if ($this->pageUid > 0) {
            $this->pageData = $this->pageRepository->getPage($this->pageUid, true);
        }

        // templates carry it back through their forms, so the site context survives a submit
        $this->moduleTemplate->assign('pageUid', $this->pageUid);
    }

    /**
     * Resolves the page the current request works on.
     *
     * The module has no page tree, so TYPO3 never supplies the "id" parameter on its own.
     * Without a page the site configuration cannot be found, which breaks every installation
     * running more than one site - especially when the sites use different default languages.
     * Therefore the page is taken from "id" when present and otherwise derived from the
     * record or page the current action is about.
     */
    protected function resolveCurrentPageUid(): int
    {
        // "id" as plain request parameter (docheader button, context menu, page tree)
        $currentPid = $this->request->getParsedBody()['id'] ?? $this->request->getQueryParams()['id'] ?? null;
        if ((int)$currentPid > 0) {
            return (int)$currentPid;
        }

        // "id" carried through the module's own links, where it is namespaced by Extbase
        if ($this->request->hasArgument('id') && (int)$this->request->getArgument('id') > 0) {
            return (int)$this->request->getArgument('id');
        }

        // pageContentExport / pageContentExportProccess / databaseExport
        foreach (['page', 'storages'] as $argumentName) {
            if ($this->request->hasArgument($argumentName)) {
                $value = $this->request->getArgument($argumentName);
                if (is_array($value)) {
                    $value = reset($value);
                }
                // "storages" may be a comma separated list, the first entry defines the site
                $parts = GeneralUtility::trimExplode(',', (string)$value, true);
                $first = (int)($parts[0] ?? 0);
                if ($first > 0) {
                    return $first;
                }
            }
        }

        // exportTableRowIndex / exportTableRowExport work on a single record
        if ($this->request->hasArgument('tablename') && $this->request->hasArgument('rowUid')) {
            $tablename = (string)$this->request->getArgument('tablename');
            $rowUid = (int)$this->request->getArgument('rowUid');

            return \Hyperdigital\HdTranslator\Helpers\TranslationHelper::getPidOfRecord($tablename, $rowUid);
        }

        return 0;
    }

    /**
     * Returns the site the current page belongs to, or null when it cannot be resolved.
     */
    protected function getCurrentSite(): ?\TYPO3\CMS\Core\Site\Entity\Site
    {
        if ($this->pageUid <= 0) {
            return null;
        }

        try {
            return GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($this->pageUid);
        } catch (SiteNotFoundException $e) {
            return null;
        }
    }

    /**
     * Returns available languages from the site configuration (TYPO3 v13) for source language dropdowns.
     *
     * When the site is known, exactly its languages are returned. Without a site the languages of
     * all sites are listed and prefixed with the site identifier - language ids are only unique per
     * site, so merging them silently would show a wrong language name whenever two sites disagree
     * (for example when one site uses English and another German as language 0).
     *
     * @return array<int, array{uid: int, title: string}>
     */
    protected function getAllowedSystemLanguages(): array
    {
        $allowedLanguages = [];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $this->getCurrentSite();

        if ($site !== null) {
            foreach ($site->getAllLanguages() as $siteLanguage) {
                $languageId = $siteLanguage->getLanguageId();
                $allowedLanguages[] = [
                    'uid' => $languageId,
                    'title' => $siteLanguage->getTitle() ?: ($languageId === 0 ? 'Default' : ('Language ' . $languageId)),
                ];
            }
        } else {
            $sites = $siteFinder->getAllSites();
            $showSiteIdentifier = count($sites) > 1;

            $seen = [];
            foreach ($sites as $siteIdentifier => $eachSite) {
                foreach ($eachSite->getAllLanguages() as $siteLanguage) {
                    $languageId = $siteLanguage->getLanguageId();
                    $title = $siteLanguage->getTitle() ?: ($languageId === 0 ? 'Default' : ('Language ' . $languageId));

                    if ($showSiteIdentifier) {
                        $title = $title . ' [' . $siteIdentifier . ']';
                    }

                    // identical languages of different sites are listed only once
                    $key = $languageId . '-' . $title;
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $allowedLanguages[] = [
                        'uid' => $languageId,
                        'title' => $title,
                    ];
                }
            }
        }

        if (empty($allowedLanguages)) {
            $allowedLanguages = [
                ['uid' => 0, 'title' => 'Default'],
            ];
        }

        // Sort by language id so Default (0) is first
        usort($allowedLanguages, static fn(array $a, array $b): int => $a['uid'] <=> $b['uid']);

        return $allowedLanguages;
    }

    // HELPERS
    /**
     * EXT:extensionmanager is optional in composer based installations. It is only needed to scan
     * all extensions for locallang files, so its absence just disables that single feature.
     */
    protected function isExtensionManagerAvailable(): bool
    {
        return class_exists(ListUtility::class) && class_exists(Extension::class);
    }

    /**
     * Reads the labels of one XLF file for one language.
     *
     * TYPO3 v14 removed LanguageService::includeLLFile(), which returned every language of a file
     * at once. Its replacement getLabelsFromResource() returns a flat "key => label" map for the
     * language the service was initialized with, so source and target are read in two passes and
     * reassembled into the structure the detail view and the exports already work with:
     *
     *   [<language>][<key>][0] => ['source' => <default label>, 'target' => <translated label>]
     *
     * Keeping that shape confines the breaking change to this method.
     *
     * @param string $filePath EXT: reference of the XLF file
     * @param string $languageKey language to read
     * @return array
     */
    protected function loadLabels(string $filePath, string $languageKey): array
    {
        $this->languageService->init('default');
        $sourceLabels = $this->languageService->getLabelsFromResource($filePath);

        if ($languageKey === 'default' || $languageKey === 'en') {
            $targetLabels = $sourceLabels;
        } else {
            $this->languageService->init($languageKey);
            $targetLabels = $this->languageService->getLabelsFromResource($filePath);
        }

        $data = [
            'default' => [],
            $languageKey => [],
        ];

        // a translation may carry keys the default file no longer has, so both sides are merged
        foreach (array_keys($sourceLabels + $targetLabels) as $key) {
            $source = (string)($sourceLabels[$key] ?? '');
            // an untranslated - or empty - label falls back to the source, as the removed API did
            $target = (string)($targetLabels[$key] ?? '');
            if ($target === '') {
                $target = $source;
            }

            $data['default'][$key] = [0 => ['source' => $source, 'target' => $source]];
            $data[$languageKey][$key] = [0 => ['source' => $source, 'target' => $target]];
        }

        return $data;
    }

    /**
     * Reads an optional language uid from the request.
     *
     * An empty selection means "no language", which is not the same as language 0, so null is
     * returned in that case. Missing arguments are tolerated, because the export actions are also
     * reachable from links that do not carry the full form.
     *
     * @param string $argumentName
     * @param int|null $default
     * @return int|null
     */
    protected function getOptionalLanguageUidArgument(string $argumentName, ?int $default = null): ?int
    {
        if (!$this->request->hasArgument($argumentName)) {
            return $default;
        }

        $value = $this->request->getArgument($argumentName);
        if ($value === '' || $value === null || !is_numeric($value)) {
            return $default;
        }

        return (int)$value;
    }

    /**
     * Adds the resolved page to link arguments, so the site context survives navigation
     * inside the module (the module has no page tree that would keep "id" alive).
     *
     * @param array $arguments
     * @return array
     */
    protected function withPageContext(array $arguments = []): array
    {
        if ($this->pageUid > 0 && !isset($arguments['id'])) {
            $arguments['id'] = $this->pageUid;
        }

        return $arguments;
    }

    /**
     * Builds a file download response instead of echoing the payload and killing the request.
     *
     * @param string $content raw file content
     * @param string $filename name offered to the browser
     * @param string $contentType mime type of the payload
     */
    protected function fileDownloadResponse(string $content, string $filename, string $contentType): ResponseInterface
    {
        $response = $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Description', 'File Transfer')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Transfer-Encoding', 'binary')
            ->withHeader('Expires', '0')
            ->withHeader('Pragma', 'public')
            ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');

        $response->getBody()->write($content);

        return $response;
    }

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
            $this->moduleTemplate->assign(
                'enabledSync',
                \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')
                    && $this->isExtensionManagerAvailable()
            );
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
            ->setHref($uriBuilder->reset()->uriFor('index', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
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
            $this->moduleTemplate->addFlashMessage(
                \TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('flashMessages.noDataToImport', 'hd_translator'),
                '',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
        }


        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('hd_translator');
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'] as $lang) {
                $item = $menu->makeMenuItem()->setTitle('[' . strtoupper($lang) . '] ' . $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['label'])
                    ->setHref($uriBuilder->reset()->uriFor('detail', $this->withPageContext(['keyTranslation' => $keyTranslation, 'languageTranslation' => $lang])))
                    ->setActive((strtoupper($languageTranslation) == strtoupper($lang)) ? 1 : 0);
                $menu->addMenuItem($item);
            }
        }
        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);

        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('list', $this->withPageContext(['category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']])))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $saveButton = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setDataAttributes([
                'action' => 'save'
            ])
            ->setIcon($iconFactory->getIcon('actions-save', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setTitle('Save');
        $buttonBar->addButton($saveButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if (!empty($this->pageData)) {
            $this->moduleTemplate->assign('pageData', $this->pageData);
        }

        $originalLanguageFilePath = $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['path'];
        $data = $this->loadLabels($originalLanguageFilePath, $languageTranslation);

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
            ->setHref($uriBuilder->reset()->uriFor('list', $this->withPageContext(['category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']])))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
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
        $data = $this->loadLabels($originalLanguageFilePath, $languageTranslation);
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

                ob_start();
                $writer->save('php://output');
                $content = (string)ob_get_clean();

                return $this->fileDownloadResponse(
                    $content,
                    $downloadFilename . '.xlsx',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                );
            case 'csv':
                $realData = [];
                foreach ($data[$languageTranslation] as $key => $value) {
                    $tempData = [];
                    $tempData[] = $key;
                    $tempData[] = $value[0]['source'];
                    $tempData[] = $value[0]['target'];
                    $realData[] = \TYPO3\CMS\Core\Utility\CsvUtility::csvValues($tempData);
                }

                return $this->fileDownloadResponse(
                    implode(PHP_EOL, $realData),
                    $downloadFilename . '.csv',
                    'text/csv'
                );
            case 'json':
                return $this->fileDownloadResponse(
                    json_encode($data[$languageTranslation], JSON_PRETTY_PRINT),
                    $downloadFilename . '.json',
                    'application/json'
                );
            case 'xlf':
                $absolutePath = $this->getTranslationPath($languageTranslation, $keyTranslation);

                if (!file_exists($absolutePath)) {
                    return $this->redirect('detail', null, null, [
                        'keyTranslation' => $keyTranslation,
                        'languageTranslation' => $languageTranslation
                    ]);
                }

                return $this->fileDownloadResponse(
                    (string)file_get_contents($absolutePath),
                    $downloadFilename . '.xlf',
                    'application/xliff+xml'
                );
        }

        return $this->redirect('detail', null, null, [
            'keyTranslation' => $keyTranslation,
            'languageTranslation' => $languageTranslation
        ]);
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

            return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => 0]);
        }

        $xlfFileExport = $this->dataToXlf($keyTranslation, $languageTranslation, $data);
        $path = $this->getTranslationPath($languageTranslation, $keyTranslation);
        file_put_contents($path, $xlfFileExport);

        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        if ($this->request->hasArgument('redirectToDetail') && $this->request->getArgument('redirectToDetail')) {
            return $this->redirect('detail', null,null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
        }

        return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => 1]);
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
        $this->moduleTemplate->assign('allowedLanguages', $this->getAllowedSystemLanguages());
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
        $this->moduleTemplate->assign('allowedLanguages', $this->getAllowedSystemLanguages());

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
        $sourceLanguageUid = $this->getOptionalLanguageUidArgument('sourceLanguageUid', 0) ?? 0;
        // optional: values of an already existing translation are offered as the target
        $targetLanguageUid = $this->getOptionalLanguageUidArgument('targetLanguageUid');
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
                throw new \RuntimeException('Cannot open zip archive ' . $zipPath, 1716200005);
            }
        }

        $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);
        $output = '';
        foreach ($storages as $storage) {
            foreach($tables as $tablename) {
                $contentRows = $databaseEntriesService->getAllCompleteteRowsForPid($tablename, (int) $storage, $sourceLanguageUid, false);
                if(empty($contentRows)) {
                    $output .= ' No entries in '.$tablename.' for pid '.$storage;
                } else {
                    foreach ($contentRows as $contentRowUid => $contentRow) {
                        if ($saveToZip) {
                            $output = '';
                        }
                        $defaultUid = (int)$contentRowUid;
                        $contentRowForKeys = $contentRow;
                        if ((int)($contentRow['sys_language_uid'] ?? 0) !== 0 && !empty($contentRow['l10n_parent'] ?? null)) {
                            $defaultUid = (int)$contentRow['l10n_parent'];
                            $contentRowForKeys['uid'] = $defaultUid;
                        }
                        // keys use the default language uid, values and children come from $contentRowUid
                        $cleanRow = $databaseEntriesService->getExportFields($tablename, $contentRowForKeys, (int)$contentRowUid);
                        $output .= $databaseEntriesService->exportDatabaseRowToXlf($defaultUid, $cleanRow, $targetLanguage, $tablename, $enableTranslatedData, $source, $targetLanguageUid);

                        if ($saveToZip) {
                            $zipFilename = "$tablename-{$contentRow['pid']}-{$defaultUid}.xlf";
                            if (version_compare(PHP_VERSION, '8.0.0') >= 0) {
                                $zip->addFromString($zipFilename, $output, \ZipArchive::FL_OVERWRITE);
                            } else {
                                $zip->addFromString($zipFilename, $output);
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
            if (file_exists($zipPath)) {
                $zipContent = (string)file_get_contents($zipPath);
                \Hyperdigital\HdTranslator\Services\FileService::rmdir($zipFolder);

                return $this->fileDownloadResponse($zipContent, basename($zipPath), 'application/zip');
            }
        }

        return $this->fileDownloadResponse($output, 'page-' . $storage . '.xlf', 'text/xml');
    }

    /**
     * Description: Database import initial screen
     */
    public function databaseImportIndexAction()
    {
        $this->indexMenu();
        $this->moduleTemplate->assign('allowedLanguages', $this->getAllowedSystemLanguages());

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
            ->setHref($uriBuilder->reset()->uriFor('index', $this->withPageContext()))
            ->setActive('index' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.pageContentExport', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('pageContentExport', $this->withPageContext()))
            ->setActive('pageContentExport' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.database', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('database', $this->withPageContext()))
            ->setActive('database' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        if ($this->request->getControllerActionName() == 'exportTableRowIndex') {
            // this action only works with its record, so the arguments have to be carried over
            $rowArguments = $this->withPageContext([
                'tablename' => (string)$this->request->getArgument('tablename'),
                'rowUid' => (int)$this->request->getArgument('rowUid'),
            ]);

            $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.exportTableRowIndex', 'hd_translator'))
                ->setHref($uriBuilder->reset()->uriFor('exportTableRowIndex', $rowArguments))
                ->setActive(1);
            $menu->addMenuItem($item);
        }

        $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.databaseImportIndex', 'hd_translator'))
            ->setHref($uriBuilder->reset()->uriFor('databaseImportIndex', $this->withPageContext()))
            ->setActive('databaseImportIndex' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        if (!empty($this->deeplApiKey)) {
            $item = $menu->makeMenuItem()->setTitle(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('docHeader.deeplTranslations', 'hd_translator'))
                ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList', $this->withPageContext()))
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
        $this->moduleTemplate->assign('allowedLanguages', $this->getAllowedSystemLanguages());
        $this->moduleTemplate->assign('rowType', \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$rowType);
        $this->moduleTemplate->assign('rowTypeCouldBe', \Hyperdigital\HdTranslator\Services\DatabaseEntriesService::$rowTypeCouldBe);

        return $this->moduleTemplate->renderResponse('Be/Translator/ExportTableRowIndex');
    }

    /**
     * @param string $tablename
     * @param int $rowUid
     */
    public function exportTableRowExportAction(string $tablename, int $rowUid)
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);
        $sourceLanguageUid = $this->getOptionalLanguageUidArgument('sourceLanguageUid', 0) ?? 0;
        // optional: values of an already existing translation are offered as the target
        $targetLanguageUid = $this->getOptionalLanguageUidArgument('targetLanguageUid');
        $row = $databaseEntriesService->getCompleteRow($tablename, $rowUid, $sourceLanguageUid);
        $label = $databaseEntriesService->getFilenameFromLabel($tablename, $row);

        $defaultUid = (int)($row['uid']);
        $rowForKeys = $row;
        if ((int)($row['sys_language_uid'] ?? 0) !== 0 && !empty($row['l10n_parent'] ?? null)) {
            $defaultUid = (int)$row['l10n_parent'];
            $rowForKeys['uid'] = $defaultUid;
        }
        // getCompleteRow() already rewrote "uid" to the default language, so the uid of the record
        // the values come from is taken from the row it stored it in
        $sourceUid = (int)($row[\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::SOURCE_UID_FIELD] ?? $defaultUid);
        $cleanRow = $databaseEntriesService->getExportFields($tablename, $rowForKeys, $sourceUid);
        $output = $databaseEntriesService->exportDatabaseRowToXlf($defaultUid, $cleanRow, $this->request->getArgument('language'), $tablename, true, $this->request->getArgument('source'), $targetLanguageUid);

        return $this->fileDownloadResponse($output, $label . '.xlf', 'text/xml');
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
        // optional: values of an already existing translation are offered as the target
        $targetLanguageUid = $this->getOptionalLanguageUidArgument('targetLanguageUid');

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
                throw new \RuntimeException('Cannot open zip archive ' . $zipPath, 1716200005);
            }
        }


        $output = '';
        foreach ($storages as $storage) {
            $contentArray = $databaseEntriesService->getCompleteContentForPage((int)$storage, (int) $sourceLanguage, $targetLanguage, true, $targetLanguageUid);

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

            // an empty archive is invalid and gets removed by ZipArchive, fall back to the plain xlf
            if (file_exists($zipPath)) {
                $zipContent = (string)file_get_contents($zipPath);
                \Hyperdigital\HdTranslator\Services\FileService::rmdir($zipFolder);

                return $this->fileDownloadResponse($zipContent, basename($zipPath), 'application/zip');
            }
        }

        return $this->fileDownloadResponse($output, 'page-' . $storage . '.xlf', 'text/xml');
    }

    public function databaseTableFieldsAction()
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('database', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
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

        $this->moduleTemplate->assign('allowedLanguages', $this->getAllowedSystemLanguages());
        $this->moduleTemplate->assign('tables', $fields);

        return $this->moduleTemplate->renderResponse('Be/Translator/DatabaseTableFields');
    }

    public function syncLocallangsAction()
    {

        if (!$this->isExtensionManagerAvailable()) {
            $this->moduleTemplate->addFlashMessage(
                'Synchronizing all locallang files needs EXT:extensionmanager, which is not installed.',
                '',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );

            return $this->redirect('index');
        }

        $listOfExtensions = GeneralUtility::makeInstance(ListUtility::class)->getAvailableExtensions();

        foreach ($listOfExtensions as $key => $extConf) {
            $extConfig = Extension::createFromExtensionArray($extConf);

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
    public function searchAction(string $sword = '')
    {
        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('index', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $data = [];
        $return = [];
        $temp = [];

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator']) && !empty($this->storage) && trim($sword) !== '') {
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
        $this->moduleTemplate->assign('sword', $sword);

        return $this->moduleTemplate->renderResponse('Be/Translator/Search');
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
        $this->moduleTemplate->assign('language', $language);

        $uriBuilder = $this->uriBuilder->setRequest($this->request);
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setTitle('Return');
        $buttonBar->addButton($returnButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $uriBuilder->setRequest($this->request);
        $buttonBar = $this->moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $returnButton = $buttonBar->makeLinkButton()
            ->setHref($uriBuilder->reset()->uriFor('deeplRemoveAllStrings', $this->withPageContext(['language' => $language])))
            ->setIcon($iconFactory->getIcon('actions-edit-delete', IconSize::SMALL))
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
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
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
            ->setHref($uriBuilder->reset()->uriFor('deeplTranslationsList', $this->withPageContext()))
            ->setIcon($iconFactory->getIcon('actions-arrow-down-left', IconSize::SMALL))
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

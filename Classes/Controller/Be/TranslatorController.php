<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Controller\Be;

use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\Menu\Menu;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
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

class TranslatorController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    protected $storage = '';
    protected $listOfPossibleLanguages = [];
    protected $conigurationFile = 'locallangConf.php';
    protected $defaultFilename = 'locallang.xlf';
    protected $relativePathToLangFilesInExt = 'Resources/Private/Language';
    protected $langFiles = [];
    protected $listUtility;
    protected $languageService;

    public function __construct(
        ListUtility     $listUtility,
        ModuleTemplate  $moduleTemplate,
        LanguageService $languageService
    )
    {
        $this->listUtility = $listUtility;
        $this->moduleTemplate = $moduleTemplate;
        $this->languageService = $languageService;
    }

    public function initializeAction()
    {
        parent::initializeAction();

        $this->storage = \Hyperdigital\HdTranslator\Helpers\TranslationHelper::getStoragePath();
        $this->listOfPossibleLanguages = GeneralUtility::makeInstance(Locales::class)->getLanguages();

        if (empty($this->storage) && \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($this->storage . $this->conigurationFile)) {
                require $this->storage . $this->conigurationFile;
            } else {
                $this->redirect('syncLocallangs');
            }
        }
    }

    public function indexAction()
    {
        if (empty($this->storage)) {
            $this->view->assign('emptyStorage', 1);
        } else {
            if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'])) {
                $data = [];
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

            $this->view->assign('categories', $data);
            $this->view->assign('enabledSync', \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs'));
        }

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    /**
     * @param string $category
     */
    public function listAction(string $category)
    {
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
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

            $this->view->assign('data', $data);
            $this->view->assign('languagesArray', $this->listOfPossibleLanguages);
        }
        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
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
        }

        file_put_contents($this->storage . $this->conigurationFile, "<?php\n" . '$GLOBALS["TYPO3_CONF_VARS"]["translator"] = ' . var_export($this->langFiles, true) . ';');
        $this->redirect('index');
    }

    protected function getAllLangFilesFromPath($extConfig, $path, $extPath, $key)
    {
        if (file_exists($path)) {
            $files = scandir($path);
            if ($files) {
                foreach ($files as $filename) {
                    if ($filename == '.' || $filename == '..') {
                        continue;
                    }

                    if (is_dir($path . '/' . $filename)) {
                        $this->getAllLangFilesFromPath($extConfig, $path . '/' . $filename, $extPath . '/' . $filename, $key);
                    } else {
                        // Check if it's not default language
                        if (in_array(explode('.', $filename)[0], array_keys($this->listOfPossibleLanguages))) {
                            continue;
                        }

                        $label = $extConfig->getExtensionKey();
                        if ($extConfig->getTitle()) {
                            $label = $extConfig->getTitle();
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
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
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

        $this->view->assign('languagesArray', $this->listOfPossibleLanguages);
        $this->view->assign('data', $return);

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     * @param string $format
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
                if ($languageTranslation == 'en' || $languageTranslation == 'default') {
                    $filename = $keyTranslation . '.xlf';
                } else {
                    $filename = $languageTranslation . '.' . $keyTranslation . '.xlf';
                }

                $downloadFilename = $downloadFilename . '.xlf';

                $absolutePath = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);
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
     * @param boolean $saved
     */
    public function detailAction($keyTranslation, $languageTranslation, $saved = false)
    {
        if (\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($this->storage . $this->conigurationFile)) {
                require $this->storage . $this->conigurationFile;
            } else {
                $this->redirect('syncLocallangs');
            }
        }

        if ($saved) {
            $this->moduleTemplate->addFlashMessage('Successfully saved');
        }

        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('hd_translator');
        if (!empty($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'] as $lang) {
                $item = $menu->makeMenuItem()->setTitle('[' . strtoupper($lang) . '] ' . $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['label'])
                    ->setHref($uriBuilder->reset()->uriFor('detail', ['keyTranslation' => $keyTranslation, 'languageTranslation' => $lang]))
                    ->setActive(($languageTranslation == $lang) ? 1 : 0);
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


        if (false && !in_array($languageTranslation, $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            $this->view->assign('is_empty', true);
        } else {
            $originalLanguageFilePath = $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['path'];
            $this->languageService->init($languageTranslation);
            $data = $this->languageService->includeLLFile($originalLanguageFilePath);

            if ($keyTranslation != 'en' && $keyTranslation != 'default' && empty($data[$languageTranslation])) {
                $this->redirect('newLangauge', null, null, [
                    'keyTranslation' => $keyTranslation,
                    'languageTranslation' => $languageTranslation
                ]);
            }

            $this->view->assign('data', $data);
            $this->view->assign('langaugeKey', $languageTranslation);
            $this->view->assign('translationKey', $keyTranslation);

            $this->view->assign('accessibleLanguages', $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages']);
        }

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     */
    public function saveAction($keyTranslation, $languageTranslation)
    {
        $data = $this->request->getArgument('data');

        $domtree = new \DOMDocument('1.0', 'UTF-8');
        $domtree->preserveWhiteSpace = false;
        $domtree->formatOutput = true;
        $xmlRoot = $domtree->createElement('xliff');
        $xmlRoot->setAttribute('version', '1.0');

        $file = $domtree->createElement('file');
        $file->setAttribute('source-language', 'en');
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
                $valSource = $domtree->createTextNode($value['default']);
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

        if ($languageTranslation == 'en' || $languageTranslation == 'default') {
            $filename = $keyTranslation . '.xlf';
        } else {
            $filename = $languageTranslation . '.' . $keyTranslation . '.xlf';
        }

        $path = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);
        file_put_contents($path, $domtree->saveXML());

        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        $this->redirect('detail', null, null, [
            'keyTranslation' => $keyTranslation,
            'languageTranslation' => $languageTranslation,
            'saved' => true
        ]);
    }

    protected function exec_enabled() {
        $disabled = explode(',', ini_get('disable_functions'));
        return !in_array('exec', $disabled);
    }
}
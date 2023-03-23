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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
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
    protected $langFiles = [];
    protected $listUtility;
    protected $languageService;
    protected $flexFormService;

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

    protected $pageRepository;

    public function __construct(
        ListUtility     $listUtility,
        ModuleTemplate  $moduleTemplate,
        LanguageService $languageService,
        FlexFormService $flexFormService,
        PageRepository $pageRepository
    )
    {
        $this->listUtility = $listUtility;
        $this->moduleTemplate = $moduleTemplate;
        $this->languageService = $languageService;
        $this->flexFormService = $flexFormService;
        $this->pageRepository = $pageRepository;

        if (\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('id')) {
            $this->pageUid = (int)\TYPO3\CMS\Core\Utility\GeneralUtility::_GP('id');
            $this->pageData = $pageRepository->getPage($this->pageUid, true);
        }
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

    protected function indexMenu()
    {
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
        $uriBuilder->setRequest($this->request);

        $menu = $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->makeMenu();
        $menu->setIdentifier('hd_translator_index');

        // Static strings
        $item = $menu->makeMenuItem()->setTitle('Static strings')
            ->setHref($uriBuilder->reset()->uriFor('index', null))
            ->setActive('index' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $item = $menu->makeMenuItem()->setTitle('Database entries')
            ->setHref($uriBuilder->reset()->uriFor('database', null))
            ->setActive('database' == $this->request->getControllerActionName() ? 1 : 0);
        $menu->addMenuItem($item);

        $this->moduleTemplate->getDocHeaderComponent()->getMenuRegistry()->addMenu($menu);
    }

    /**
     * @param string $tablename
     * @param int $rowUid
     */
    public function exportTableRowIndexAction(string $tablename, int $rowUid)
    {
        $databaseEntriesService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\DatabaseEntriesService::class);
        $row = $databaseEntriesService->getCompleteRow($tablename, $rowUid);

        $label = $databaseEntriesService->getLabel($tablename, $row);

        $this->view->assign('tablename', $tablename);
        $this->view->assign('rowUid', $rowUid);
        $this->view->assign('label', $label);
        $this->view->assign('fields', $databaseEntriesService->getExportFields($tablename, $row));

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    public function indexAction()
    {
        if (empty($this->storage)) {
            $this->view->assign('emptyStorage', 1);
        } else {
            $this->indexMenu();

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

            if (!empty($this->pageData)) {
                $this->view->assign('pageData', $this->pageData);
            }
            $this->view->assign('categories', $data);
            $this->view->assign('enabledSync', \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs'));
        }

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    public function databaseAction()
    {
        $this->indexMenu();

        $tables = [];
        foreach ($GLOBALS['TCA'] as $tableName => $data) {
            if (!empty($data['ctrl']['languageField'])) {
                $tables[] = [
                    'tableName' => $tableName,
                    'tableTitle' => !empty($data['ctrl']['title']) ? $data['ctrl']['title'] : $tableName,
                ];
            }
        }

        $this->view->assign('tables', $tables);

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
    }

    public function databaseTableFieldsAction()
    {
        $uriBuilder = $this->objectManager->get(UriBuilder::class);
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


        $this->view->assign('allowedLanguages', $allowedLanguages);
        $this->view->assign('tables', $fields);

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
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

    public function databaseImportAction()
    {
        $errors = [];

        if (!$this->request->hasArgument('file')) {
            $errors[] = 'No file uploaded';
        } else {
            $file = $this->request->getArgument('file');

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $ext = $finfo->file($file['tmp_name']);
            switch($ext) {
                case 'text/xml':
                    $temp = explode('.', $file['name']);
                    switch ($temp[count($temp) - 1]) {
                        case 'xlf':
                            $content = file_get_contents($file['tmp_name']);
                            $this->importXlfFile($content);
                            break;
                        case 'xml':
                            $content = file_get_contents($file['tmp_name']);
                            $this->importXmlFile($content);
                            break;
                    }
                    break;
            }
        }

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
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
//                                        DebuggerUtility::var_dump($newSettings);
//                                        die();
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
                            if (!empty($this->originalData[$table][$uid]['colPos']) && empty($fieldsToSync['colPos'])) {
                                $tempQueryUpdate->set('colPos', $this->originalData[$table][$uid]['colPos']);
                            }
                            if (!empty($this->originalData[$table][$uid]['list_type']) && empty($fieldsToSync['list_type'])) {
                                $tempQueryUpdate->set('list_type', $this->originalData[$table][$uid]['list_type']);
                            }
                            if (isset($this->originalData[$table][$uid]['l10n_source'])) {
                                $tempQueryUpdate->set('l10n_source', $uid);
                            }

//                            if ($this->originalData[$table][$uid]['uid'] == 15664) {
//                                echo '<pre>';
//                                var_dump(htmlentities($fieldsToSync['pi_flexform']));
//                                var_dump(htmlentities($this->superOriginalData[$table][$uid]['pi_flexform']));
//                                echo '</pre>';
//                                die();
//                            }

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
                                    if (!empty($originalData['colPos']) && empty($insert['colPos'])) {
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

        $this->view->assign('actions', $output);
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

    public function databaseExportAction()
    {
        $errors = [];

        if (!$this->request->hasArgument('languageFrom')) {
            $errors[] = 'Language from is missing';
        } else {
            $sourceLangauge = $this->request->getArgument('languageFrom');
        }
        if (!$this->request->hasArgument('languageTo')) {
            $errors[] = 'Language to is missing';
        } else {
            $targetLangauge = $this->request->getArgument('languageTo');
        }
        if (!$this->request->hasArgument('tables')) {
            $errors[] = 'Tables is missing';
        } else {
            $tablesPRE = $this->request->getArgument('tables');
        }

        $storage = [];
        if ($this->request->hasArgument('storage')) {
            if ($this->request->hasArgument('sublevels') && $this->request->getArgument('sublevels')) {
                $this->getAllPagesFromRoot($this->request->getArgument('storage'), $storage);
            } else {
                $storage = explode(',', $this->request->getArgument('storage'));
            }
        }

        $tables = [];
        foreach ($tablesPRE as $table) {
            $table = explode('.', $table);
            $tables[$table[0]][] = $table[1];
        }

        $data = [];

        $disabledTcaTypes = ['slug'];
        $disableRenderTypes = ['insights'];

        $sourceLanguageLetters = 'en';
        if ($sourceLangauge != 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_language')->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $tempQuery = $queryBuilder->select('language_isocode')->from('sys_language')
                ->where(
                    $queryBuilder->expr()->eq('uid', $sourceLangauge)
                );
            $result = $tempQuery->execute();
            $row = $result->fetch();
            if ($row) {
                $sourceLanguageLetters = $row['language_isocode'];
            }
        }
        $targetLanguageLetters = 'en';
        if ($targetLangauge != 0) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_language')->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $result = $queryBuilder->select('language_isocode')->from('sys_language')
                ->where(
                    $queryBuilder->expr()->eq('uid', $targetLangauge)
                )
                ->execute();
            $row = $result->fetch();
            if ($row) {
                $targetLanguageLetters = $row['language_isocode'];
            }
        }
        foreach ($tables as $table => $columns) {
            $sourceUidField = 'uid';
            if ($sourceLangauge != 0) {
                $sourceUidField = 'l10n_parent';
                if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                    $sourceUidField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
                }
            }
            $targetUidField = 'uid';
            if ($targetLangauge != 0) {
                $targetUidField = 'l10n_parent';
                if (!empty($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'])) {
                    $targetUidField = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'];
                }
            }

            if (!in_array('uid', $columns)) {
                $columns[] = 'uid';
            }
            if (!in_array($sourceUidField, $columns)) {
                $columns[] = $sourceUidField;
            }
            if (!in_array($targetUidField, $columns)) {
                $columns[] = $targetUidField;
            }


            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $langaugeField = 'sys_language_uid';
            if (!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])) {
                $langaugeField = $GLOBALS['TCA'][$table]['ctrl']['languageField'];
            }

            $tempQuery = $queryBuilder->select(...$columns)->from($table);
            if (!empty($storage)) {
                if ($table == 'pages' && $sourceLangauge == 0) {
                    $tempQuery->where(
                        $queryBuilder->expr()->eq($langaugeField, $sourceLangauge),
                        $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($storage, Connection::PARAM_INT_ARRAY))
                    );
                } else {
                    $tempQuery->where(
                        $queryBuilder->expr()->eq($langaugeField, $sourceLangauge),
                        $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($storage, Connection::PARAM_INT_ARRAY))
                    );
                }
            } else {
                $tempQuery->where(
                    $queryBuilder->expr()->eq($langaugeField, $sourceLangauge)
                );
            }
            $result = $tempQuery->execute();
            while ($row = $result->fetch()) {
                $key = $targetLangauge.'.'.$table.'.'.$row['uid'];
                $realUid = $row[$sourceUidField];

                //remove unnecessary keys
                if(isset($row[$sourceUidField])) unset($row[$sourceUidField]);
                if(isset($row[$targetUidField])) unset($row[$targetUidField]);
                if(isset($row['uid'])) unset($row['uid']);

                $resultTarget = $queryBuilder->select(...array_keys($row))->from($table)
                    ->where(
                        $queryBuilder->expr()->eq($langaugeField, $targetLangauge),
                        $queryBuilder->expr()->eq($targetUidField, $realUid)
                    )
                    ->execute();
                $rowTarget = $resultTarget->fetch();

                foreach ($row as $tableColumn => $tableValue) {
                    // TODO: if flexform
                    $type = $GLOBALS['TCA'][$table]['columns'][$tableColumn]['config']['type'];

                    if ($type == 'flex') {
                        $flexformDataOriginal = $this->flexFormService
                            ->convertFlexFormContentToArray(strval($tableValue));
                        $flexformDataTarget = $this->flexFormService
                            ->convertFlexFormContentToArray(strval($rowTarget[$tableColumn]));
                        $this->databaseFlexformDataToTranslationArray($data, $key . '.' . $tableColumn, $targetLanguageLetters, $flexformDataOriginal, $flexformDataTarget);
                    } else {
                        if (!empty($rowTarget[$tableColumn]) || !empty($tableValue) ) {
                            $data[$key . '.' . $tableColumn]['default'] = strval($tableValue);
                            $data[$key . '.' . $tableColumn][$targetLanguageLetters] = strval(!empty($rowTarget[$tableColumn]) ? $rowTarget[$tableColumn] : $tableValue);
                        }
                    }
                }

            }
        }

        if (!$this->request->hasArgument('format')) {
            $this->exportDatabaseToXlf($data, $sourceLanguageLetters, $targetLanguageLetters);
        } else {
            switch ($this->request->getArgument('format')) {
                case 'xml':
                    $this->exportDatabaseToXlm($data, $targetLanguageLetters);
                    break;
                case 'csv':
                    $realData = [];
                    foreach ($data as $key => $value) {
                        $tempData = [];
                        $tempData[] = $key;
                        $tempData[] = $value['default'];
                        $tempData[] = $value[$targetLanguageLetters];
                        $realData[] = \TYPO3\CMS\Core\Utility\CsvUtility::csvValues($tempData);
                    }

                    $downloadFilename = 'temp' . '.csv';
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Pragma: public');
                    echo implode(PHP_EOL, $realData);
                    break;
                default:
                    $this->exportDatabaseToXlf($data, $sourceLanguageLetters, $targetLanguageLetters);
                    break;
            }
        }

        die();
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

        if (!empty($this->pageData)) {
            $this->view->assign('pageData', $this->pageData);
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
     * @param boolean $emptyImport
     */
    public function detailAction($keyTranslation, $languageTranslation, $saved = false, $emptyImport = false)
    {
        if (\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'allLocallangs')) {
            if (file_exists($this->storage . $this->conigurationFile)) {
                require $this->storage . $this->conigurationFile;
            } else {
                $this->redirect('syncLocallangs');
            }
        }

        if ($saved) {
            $this->moduleTemplate->addFlashMessage(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('flashMessages.sucecssfullySaved', 'hd_translator'));
        }
        if ($emptyImport) {
            $this->moduleTemplate->addFlashMessage(\TYPO3\CMS\Extbase\Utility\LocalizationUtility::translate('flashMessages.noDataToImport', 'hd_translator'), '', \TYPO3\CMS\Core\Messaging\FlashMessage::ERROR);
        }


        $uriBuilder = $this->objectManager->get(UriBuilder::class);
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
            $this->view->assign('pageData', $this->pageData);
        }

        if (false && !in_array($languageTranslation, $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages'])) {
            $this->view->assign('is_empty', true);
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
                $this->view->assign('data', $output);
                $this->view->assign('isCategorized', true);
            } else {
                $this->view->assign('data', $data);
            }

            $this->view->assign('langaugeKey', $languageTranslation);
            $this->view->assign('translationKey', $keyTranslation);

            $this->view->assign('accessibleLanguages', $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['languages']);
        }

        $this->moduleTemplate->setContent($this->view->render());
        return $this->moduleTemplate->renderContent();
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

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     */
    public function removeAction($keyTranslation, $languageTranslation)
    {
        if ($languageTranslation == 'en' || $languageTranslation == 'default') {
            $filename = $keyTranslation . '.xlf';
        } else {
            $filename = $languageTranslation . '.' . $keyTranslation . '.xlf';
        }

        $path = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);

        if (file_exists($path)) {
            unlink($path);
        }

        $this->redirect('list', null, null, [
            'category' => $GLOBALS['TYPO3_CONF_VARS']['translator'][$keyTranslation]['category']
        ]);
    }

    /**
     * @param string $keyTranslation
     * @param string $languageTranslation
     */
    public function importAction($keyTranslation, $languageTranslation)
    {
        if (!$this->request->hasArgument('file')) {
            $this->redirect('detail',  null, null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation]);
        }
        $file = $this->request->getArgument('file');
        $extension = explode('.', $file['name']);
        $extension = strtolower($extension[count($extension) - 1]);
        $filePath = $file['tmp_name'];
        $content = file_get_contents($filePath);
        $data = [];

        switch($extension) {
            case 'xlf':
                $doc = new \DOMDocument();
                $doc->loadXML($content);
                $items = $doc->getElementsByTagName('trans-unit');
                if ($items) {
                    foreach ($items as $item) {
                        $key = $item->getAttribute('id');

                        $source = '';
                        $sourceData = $item->getElementsByTagName('source');
                        if ($sourceData[0]) {
                            $source = $sourceData[0]->textContent;
                        }
                        $target = $source;
                        $targetData = $item->getElementsByTagName('target');
                        if ($targetData[0]) {
                            $target = $targetData[0]->textContent;
                        }

                        $data[$key] = [
                            'default' => $source,
                            $languageTranslation => $target
                        ];
                    }
                }
                break;
            case 'json':
                $dataArray = json_decode($content);
                if ($dataArray) {
                    foreach ($dataArray as $key => $value) {
                        $data[$key] = [
                            'default' => $value[0]->source,
                            $languageTranslation => $value[0]->target
                        ];
                    }
                }
                break;
            case 'csv':
                $dataArray = \TYPO3\CMS\Core\Utility\CsvUtility::csvToArray($content);
                if ($dataArray) {
                    foreach ($dataArray as $value) {
                        $data[$value[0]] = [
                            'default' => $value[1],
                            $languageTranslation => $value[2]
                        ];
                    }
                }
                break;
            case 'xls':
            case 'xlsx':
                $temp = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                $temp = $temp->load($filePath);
                $temp = $temp->getActiveSheet();
                $rows = $temp->toArray();

                foreach($rows as $key => $value) {
                    $data[$value[0]] = [
                        'default' => $value[1],
                        $languageTranslation => $value[2]
                    ];
                }
                break;
        }

        if (!empty($data)) {
            $this->redirect('save', null, null, ['keyTranslation' => $keyTranslation, 'languageTranslation' => $languageTranslation, 'data' => $data]);
        }

        $this->redirect('detail', null, null, [
            'keyTranslation' => $keyTranslation,
            'languageTranslation' => $languageTranslation,
            'emptyImport' => true
        ]);
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


    /**
     * @\TYPO3\CMS\Extbase\Annotation\IgnoreValidation("data")
     *
     * @param string $keyTranslation
     * @param string $languageTranslation
     * @param array $data
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
            echo json_encode(['success' => 0]);
            die();
        }

        $xlfFileExport = $this->dataToXlf($keyTranslation, $languageTranslation, $data);

        if ($languageTranslation == 'en' || $languageTranslation == 'default') {
            $filename = $keyTranslation . '.xlf';
        } else {
            $filename = $languageTranslation . '.' . $keyTranslation . '.xlf';
        }

        if (!empty($this->pageUid)) {
            $filename = $this->pageUid . '.' . $filename;
        }

        $path = \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName($this->storage . $filename);
        file_put_contents($path, $xlfFileExport);

        GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->flushCachesInGroup('system');

        echo json_encode(['success' => 1]);
        die();
    }

    protected function exec_enabled() {
        $disabled = explode(',', ini_get('disable_functions'));
        return !in_array('exec', $disabled);
    }
}

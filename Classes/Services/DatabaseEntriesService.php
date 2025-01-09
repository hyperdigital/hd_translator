<?php
namespace Hyperdigital\HdTranslator\Services;

use Google\Service\CloudDebugger\Resource\Debugger;
use Tpwd\KeSearch\Backend\Flexform;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use TYPO3\CMS\Core\Service\FlexFormService;

class DatabaseEntriesService
{
    public static $databaseEntriesOriginal = [];
    public static $databaseEntriesTranslated = [];
    public static $rowType = '';
    public static $rowTypeCouldBe = '';
    protected $updateMmRelations = [];

    // Ignore  $GLOBALS['TCA'][$table]['types'][1]['translator_export']
    protected $ignoreExportFields = false;
    public function setIgnoreExportFields($exportSettings)
    {
        $this->ignoreExportFields = $exportSettings;
    }

    /**
     * @var bool this will disable inserting or updating data
     */
    public static $onlyDebug = false;


    public static $importStats = ['updates' => 0, 'inserts' => 0, 'fails' => 0, 'failsMessages' => []];
    protected $updateAfterImport = [];

    protected $flexFormService;

    public function __construct(
        FlexFormService $flexFormService
    )
    {
        $this->flexFormService = $flexFormService;
    }

    public function getListOfTranslatableFields($tablename, $row, &$typeArrayReturn = [])
    {
        if (isset($GLOBALS['TCA'][$tablename]['ctrl']['type']) && !empty($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']])) {
            self::$rowTypeCouldBe = $row[$GLOBALS['TCA'][$tablename]['ctrl']['type']];
        }
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]) // types has value for this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['translator_export'])
        ) {
            $typeArray = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]];
            self::$rowType = $row[$GLOBALS['TCA'][$tablename]['ctrl']['type']];
        } else {
            self::$rowType = '1';
            if (isset($GLOBALS['TCA'][$tablename]['types']['1']['translator_export'])) {
                $typeArray = $GLOBALS['TCA'][$tablename]['types']['1'];
            }
        }

        $typeArrayReturn = $typeArray ?? [];

        if (isset($typeArray['translator_export']) && $this->ignoreExportFields == false) {
            $listOfFields = GeneralUtility::trimExplode(',',$typeArray['translator_export']);
        }  else {
            $listOfFields = $this->getListOfFieldsFromRow($tablename, $row);
        }

        return $listOfFields;
    }

    protected function getFieldsDisabledFromUpdate($tablename, $row)
    {
        $listOfFields = [];
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]) // types has value for this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['translator_import_ignore'])
        ) {
            $typeArray = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]];
        } else {
            if (isset($GLOBALS['TCA'][$tablename]['types']['1']['translator_import_ignore'])) {
                $typeArray = $GLOBALS['TCA'][$tablename]['types']['1'];
            }
        }

        $typeArrayReturn = $typeArray ?? [];

        if (isset($typeArray['translator_import_ignore']) && $this->ignoreExportFields == false) {
            $listOfFields = GeneralUtility::trimExplode(',',$typeArray['translator_import_ignore']);
        }  else {
            $listOfFields = [];
        }

        return $listOfFields;
    }

    /**
     * @param string $tablename  name of the table
     * @param int|array $row UID of entry or the whole row
     * @return string
     */
    public function getLabel(string $tablename, $row)
    {
        $return = [];
        $labelField = 'title';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['label'])) {
            $labelField = $GLOBALS['TCA'][$tablename]['ctrl']['label'];
        }
        $labelFieldAlt = '';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['label_alt'])) {
            $labelFieldAlt = 'title';
        }

        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        if (!empty($row[$labelField])) {
            $return[] = $row[$labelField];
        }

        if ((empty($return) || !empty($GLOBALS['TCA'][$tablename]['ctrl']['label_alt_force']) ) && !empty($row[$labelFieldAlt])) {
            foreach (GeneralUtility::trimExplode(',', $row[$labelFieldAlt]) as $field) {
                if (!empty($row[$field])) {
                    $return[] = $row[$field];
                }
            }
        }

        if (empty($return)) {
            $return[] = $tablename.':'.$row['uid'];
        }

        return implode(', ', $return);

    }

    /**
     * @param int $parentPid - current parent page
     * @param array $storages - storage reference which would be updated (list of PIDs)
     * @param string $pageTypes - comma separated page types (doktypes)
     * @param bool $firstReference - is this function called for the firt time?
     */
    public function addAllSubpages(int $parentPid, array &$storages, string $pageTypes = '', bool $firstReference = true)
    {
        if (!empty($pageTypes)) {
            $pageTypes = GeneralUtility::trimExplode(',', $pageTypes);
        }

        $pageRepository = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class);

        $subpages = $pageRepository->getPageIdsRecursive([$parentPid], 9999);
        if ($subpages){
            foreach ($subpages as $subpage) {
                if (!in_array($subpage, $storages)) {
                    $storages[] = $subpage;
                }
            }
        }
    }


    /**
     * @param string $tablename
     * @param array | int $row
     */
    public function getFilenameFromLabel(string $tablename, $row)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        $slug = $this->getLabel($tablename, $row);

        // Convert to lowercase + remove tags
        $slug = mb_strtolower($slug, 'utf-8');
        $slug = strip_tags($slug);

        // Convert some special tokens (space, "_" and "-") to the space character
        $slug = preg_replace('/[ \t\x{00A0}\-+_]+/u', '-', $slug);

        // Convert extended letters to ascii equivalents
        // The specCharsToASCII() converts "€" to "EUR"
        $slug = GeneralUtility::makeInstance(CharsetConverter::class)->specCharsToASCII('utf-8', $slug);

        // Get rid of all invalid characters, but allow slashes
        $slug = preg_replace('/[^\p{L}\p{M}0-9\/' . preg_quote('-') . ']/u', '', $slug);

        // Convert multiple fallback characters to a single one
        $slug = preg_replace('/' . preg_quote('-') . '{2,}/', '-', $slug);

        // Ensure slug is lower cased after all replacement was done
        $slug = mb_strtolower($slug, 'utf-8');

        return $slug;
    }

    /**
     * @param string $tablename name of the table
     * @param int $rowUid UID of entry
     */
    public function getCompleteCleanRow(string $tablename, int $rowUid)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $orWhere = [];
        $orWhere[] = $queryBuilder->expr()->eq('uid', $rowUid);

        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->orWhere(
                ...$orWhere
            )
            ->executeQuery();

        $row = $result->fetchAssociative();
        
        return $row;
    }

    /**
     * @param string $tablename name of the table
     * @param int $rowUid UID of entry
     */
    public function getCompleteRow(string $tablename, int $rowUid, int $sourceLanguage = 0)
    {
        $parentUidField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'])) {
            $parentUidField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'];
        }
        $langaugeField = 'sys_language_uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            $langaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $orWhere = [];
        $orWhere[] = $queryBuilder->expr()->eq('uid', $rowUid);
        if (!empty($parentUidField)) {
            $orWhere[] = $queryBuilder->expr()->eq($parentUidField, $rowUid);
        }

        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->orWhere(
                ...$orWhere
            )
            ->andWhere(
                $queryBuilder->expr()->eq($langaugeField, $sourceLanguage)
            )
            ->executeQuery();

        $row = $result->fetchAssociative();

        // Fix of notfound entry, when wrong language is used
        if (!$row) {
            $result = $queryBuilder
                ->select('*')
                ->from($tablename)
                ->where(
                    $queryBuilder->expr()->eq('uid', $rowUid)
                )
                ->executeQuery();
            $row = $result->fetchAssociative();
        }

        //setup default lanugage uid
        if (!empty($langaugeField) && isset($row[$langaugeField]) && $row[$langaugeField] == 0) {
            $parentUidField = 'uid';
        }

        if (!empty($parentUidField) && !empty($row[$parentUidField])) {
            $row['uid'] = $row[$parentUidField];
        }

        return $row;
    }

    public function getTranslatedCompleteRow($tablename, $l10nParent, $targetLanguage)
    {
        if (empty($l10nParent)) {
            return false;
        }

        $parentUidField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'])) {
            $parentUidField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'];
        }

        if ($targetLanguage == 0) {
            $parentUidField = 'uid';
        }

        $langaugeField = 'sys_language_uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            $langaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->where(
                $queryBuilder->expr()->eq($parentUidField, $l10nParent),
                $queryBuilder->expr()->eq($langaugeField, $targetLanguage)
            )
            ->executeQuery();

        $row = $result->fetchAssociative();

        return $row;
    }

    /**
     * @param string $tablename name of the table
     * @param int $pid - PID where the rows are stored (if lower then 0 then it's over the whole database)
     * @param bool $clean - return cleaned rows
     */
    public function getAllCompleteteRowsForPid(string $tablename, int $pid, int $sourceLanguage = 0, bool $clean = false)
    {
        $return = [];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $parentUidField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'])) {
            $parentUidField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'];
        }
        $langaugeField = 'sys_language_uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            $langaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'];
        }

        $where = [];
        if ($pid < 0) {
            $where[] = $queryBuilder->expr()->gt('pid', -1);
        } else {
            $where[] = $queryBuilder->expr()->eq('pid', $pid);
        }
        $where[] = $queryBuilder->expr()->eq($langaugeField, $sourceLanguage);

        $sortByTemp = 'uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['default_sortby'])) {
            $sortByTemp = $GLOBALS['TCA'][$tablename]['ctrl']['default_sortby'];
        }

        $sortByTemp = str_replace('ORDER BY ', '', $sortByTemp);
        $sortByTemp = GeneralUtility::trimExplode(',', $sortByTemp);
        $sortBys = [];
        foreach ($sortByTemp as $val) {
            $tempKyes = explode(' ', $val);
            if (empty($tempKyes[1])) {
                $tempKyes[1] = 'ASC';
            }
            $sortBys[] = $tempKyes;
        }

        $temp = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->where(
                ...$where
            );
        foreach ($sortBys as $sortBy) {
            $temp->addOrderBy($sortBy[0], $sortBy[1]);
        }
        $result = $temp->executeQuery();

        while($row = $result->fetchAssociative()) {
            if ($clean) {
                $return[$row['uid']] = $this->getExportFields($tablename, $row);
            } else {
                $return[$row['uid']] = $row;
            }
        }

        return $return;
    }

    /**
     * @param string $tablename
     * @param int $parentUid
     * @param string $foreignField
     * @param string $foreignSortby
     * @param string $foreignTableField
     * @param array $foreignMatchFields
     * @return mixed
     */
    public function getCompleteInlinedRows(string $tablename, int $parentUid, $foreignField = '', $foreignTableField = '', $parentTableName = '',  $foreignMatchFields = [])
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $where = [];
        if (!empty($parentUid) && !empty($foreignField)) {
            $where[] = $queryBuilder->expr()->eq($foreignField, $parentUid);
        }
        if (!empty($parentTableName) && !empty($foreignTableField)) {
            $where[] = $queryBuilder->expr()->eq($foreignTableField, $queryBuilder->createNamedParameter($parentTableName));
        }
        foreach ($foreignMatchFields as $tempKey => $tempValue) {
            $where[] = $queryBuilder->expr()->eq($tempKey, $queryBuilder->createNamedParameter($tempValue));
        }

        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->where(
                ...$where
            )
            ->executeQuery();

        $return = [];
        while($row = $result->fetchAssociative()) {
            $return[] = $row;
        }

        return $return;
    }

    /**
     * returns to $return key => value for the field by it's TCA settings
     *
     * @param string $tablename
     * @param string $field
     * @param $row
     * @param string $specialFieldNameOutput
     * @param $return
     */
    protected function getFieldKeyAndValue(string $tablename, string $field, $row, &$return, $specialFieldNameOutput = '', $typeArray = [])
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        if (empty($specialFieldNameOutput)) {
            $specialFieldNameOutput = $row['uid'].'.'.$field;
        }

        if (!empty($GLOBALS['TCA'][$tablename]['columns'][$field]['config']['type'])) {
            // Switch by TCA type of the field
            switch ($GLOBALS['TCA'][$tablename]['columns'][$field]['config']['type']) {
                case 'input':
                case 'text':
                case 'slug':
                case 'color':
                case 'datetime':
                case 'email':
                case 'json':
                case 'link':
                case 'number':
                case 'password':
                    $return[$specialFieldNameOutput]['value'] = $row[$field] ?? '';
                    $return[$specialFieldNameOutput]['label'] = $this->getFieldLabel($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['html'] = $this->fieldCanContainHtml($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['slug'] = $this->isSlugField($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['table'] = $tablename;
                    $return[$specialFieldNameOutput]['uid'] = $row['uid'] ?? '';
                    $return[$specialFieldNameOutput]['field'] = $field ?? '';
                    break;
                case 'file':
                    $this->getFileTranslatableData($tablename, $field, $row, $return, $specialFieldNameOutput);
                case 'inline':
                    $this->getInlinedRowsFieldKeyAndValue($tablename, $field, $row, $return, $specialFieldNameOutput);
                    break;
                case 'flex':
                    $limitedFields = [];
                    if (isset($typeArray['translator_export_column'][$field])) {
                        $limitedFields = GeneralUtility::trimExplode(',', $typeArray['translator_export_column'][$field]);
                    }

                    $fieldPrefix = substr($specialFieldNameOutput, 0, -1 * strlen( $row['uid'].'.'.$field));

                    $this->getFlexformKeysAndValues($tablename, $field, $row, $return, $field, $limitedFields, $typeArray, $fieldPrefix);
            }
        }
    }

    /**
     * @param string $tablename
     * @param string $field
     * @param array $row
     * @param array $return
     * @param string $specialFieldNameOutput
     */
    protected function getFlexformKeysAndValues(string $tablename, string $field, array $row, array &$return, string $specialFieldNameOutput, array $limitedFields, $typeArray = [], $fieldnamePrefix = '')
    {
        $flexString = $row[$field];
        $data = $this->flexFormService
            ->convertFlexFormContentToArray(strval($flexString));

        foreach ($data as $key => $value) {
            $subarrayName = $row['uid'].'.'.$specialFieldNameOutput.'.'.$key;
            $this->subarrayToKeyValues($tablename, $field, $row['uid'], $return, $subarrayName, $value, $limitedFields, $typeArray, $fieldnamePrefix);
        }
    }

    protected function subarrayToKeyValues(string $tablename, string $field, $rowUid, &$return, $subarrayName, $value, $limitedFields, $typeArray = [], $fieldnamePrefix = '')
    {
        $subarrayFieldName = substr($subarrayName, strlen($rowUid.'.'.$field.'.')); // full name with the parent field - used in export settings
        if (is_string($value)) {
            if (empty($limitedFields) || in_array($subarrayFieldName, $limitedFields)) {
                $return[$fieldnamePrefix . $subarrayName]['value'] = $value ?? '';
                $return[$fieldnamePrefix . $subarrayName]['label'] = $subarrayName; // TODO
                $return[$fieldnamePrefix . $subarrayName]['html'] = false;// TODO
                $return[$fieldnamePrefix . $subarrayName]['slug'] = false; // TODO
                $return[$fieldnamePrefix . $subarrayName]['table'] = $tablename;
                $return[$fieldnamePrefix . $subarrayName]['uid'] = $rowUid ?? '';
                $return[$fieldnamePrefix . $subarrayName]['field'] = $fieldnamePrefix . $subarrayName ?? '';
            }
        } else if(is_array($value)) {
            foreach ($value as $newKey => $newValue) {
                if ((int) $newKey > 0) {
                    // objects
                    if (!empty($typeArray['translator_export_column'][$field.'.'.$subarrayFieldName])) {
                        $enabledFields = GeneralUtility::trimExplode(',', $typeArray['translator_export_column'][$field.'.'.$subarrayFieldName]);
                        foreach ($enabledFields as $enabledField) {
                            $newEnabledField = $subarrayFieldName . '.' . $newKey.'.'.$enabledField;
                            if (!in_array($newEnabledField, $limitedFields)){
                                $limitedFields[] = $newEnabledField;
                            }
                        }
                        $newKey = $subarrayName . '.' . $newKey;
                        $this->subarrayToKeyValues($tablename, $field, $rowUid, $return, $newKey, $newValue, $limitedFields, $typeArray, $fieldnamePrefix);
                    }
                } else {
                    $newKey = $subarrayName . '.' . $newKey;
                    $this->subarrayToKeyValues($tablename, $field, $rowUid, $return, $newKey, $newValue, $limitedFields, $typeArray, $fieldnamePrefix);
                }
            }
        }
    }

    public function getFileTranslatableData($tablename, $field, $row, &$return, $specialFieldNameOutput = '')
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('*')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('uid_foreign', $row['uid']),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tablename))
            )
            ->executeQuery();


        while($rowInlined = $result->fetchAssociative()) {
            $typeArray = [];
            $listOfFields = $this->getListOfTranslatableFields('sys_file_reference', $rowInlined, $typeArray);

            foreach ($listOfFields as $field) {
                $tempName = $specialFieldNameOutput.'.'.$rowInlined['uid'].'.'.$field;
                $this->getFieldKeyAndValue('sys_file_reference', $field, $rowInlined, $return, $tempName, $typeArray);
            }
        }
    }

    /**
     * @param string $tablename
     * @param string $field
     * @param $row
     * @param $return
     * @param string $specialFieldNameOutput
     */
    protected function getInlinedRowsFieldKeyAndValue(string $tablename, string $field, $row, &$return, $specialFieldNameOutput = '')
    {
        $foreginTable = $GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_table'];
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table']) // override label from type
        ) {
            $foreginTable = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table'];
        }
        $foreginField = $GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_field'];
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field']) // override label from type
        ) {
            $foreginField = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field'];
        }

        $parentUid = $row['uid'];
        $parentUidLanguageField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'] ?? 'l18n_parent';
        $sysLangaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'] ?? 'sys_language_uid';
        // has pointer to parent in default language and also it's not in default language
        if (!empty($row[$parentUidLanguageField]) && !empty($row[$sysLangaugeField])) {
            $parentUid = $row[$parentUidLanguageField];
        }

        $foreginField = $GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_field'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field']) // override label from type
        ) {
            $foreginField = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field'];
        }

        $foreginTableField = $GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_table_field'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field']) // override label from type
        ) {
            $foreginTableField = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field'];
        }

        if (!empty($GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_match_fields'])) {
            $foreignMatchFields = $GLOBALS['TCA'][$tablename]['columns'][$field]['config']['foreign_match_fields'];
            if (
                !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
                && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
                && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields']) // override label from type
            ) {
                $foreignMatchFields = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields'];
            }
        }

        if (empty($foreignMatchFields)) {
            $foreignMatchFields = [];
        }

        $rows = $this->getCompleteInlinedRows($foreginTable, $parentUid, $foreginField, $foreginTableField, $tablename, $foreignMatchFields);

        if (!empty($rows)) {
            foreach ($rows as $rowInlined) {
                $typeArray = [];
                $listOfFields = $this->getListOfTranslatableFields($foreginTable, $rowInlined, $typeArray);

                foreach ($listOfFields as $field) {
                    $tempName = $specialFieldNameOutput.'.'.$rowInlined['uid'].'.'.$field;
                    $this->getFieldKeyAndValue($foreginTable, $field, $rowInlined, $return, $tempName, $typeArray);
                }
            }
        }

    }

    /**
     * @param string $tablename name of the table
     * @param int|array $row UID of entry or the whole row
     */
    protected function getListOfFieldsFromRow($tablename, $row)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        // CTRL fields
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigDiffSourceField'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['transOrigDiffSourceField']]);
        } else if (isset($row['l10n_diffsource'])) {
            unset($row['l10n_diffsource']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['languageField']]);
        } else if (isset($row['sys_language_uid'])) {
            unset($row['sys_language_uid']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['l10n_source'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['l10n_source']]);
        } else if (isset($row['l10n_parent'])) {
            unset($row['l10n_parent']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['origUid'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['origUid']]);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['sortby'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['sortby']]);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['tstamp'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['tstamp']]);
        } else if (isset($row['tstamp'])) {
            unset($row['tstamp']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['cruser_id'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['cruser_id']]);
        } else if (isset($row['cruser_id'])) {
            unset($row['cruser_id']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['crdate'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['crdate']]);
        } else if (isset($row['crdate'])) {
            unset($row['crdate']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['delete'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['delete']]);
        } else if (isset($row['deleted'])) {
            unset($row['deleted']);
        }

        // CTRL editable fields
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['disabled'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['disabled']]);
        } else if (isset($row['hidden'])) {
            unset($row['hidden']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['starttime'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['starttime']]);
        } else if (isset($row['starttime'])) {
            unset($row['starttime']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['endtime'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['endtime']]);
        } else if (isset($row['endtime'])) {
            unset($row['endtime']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['fe_group'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['enablecolumns']['fe_group']]);
        } else if (isset($row['fe_group'])) {
            unset($row['fe_group']);
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['type'])) {
            unset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]);
        }


        // Base fields
        if (isset($row['uid']))  unset($row['uid']);
        if (isset($row['pid']))  unset($row['pid']);

        //Additional cleanup
        if (isset($row['l10n_state']))  unset($row['l10n_state']);
        if (isset($row['l10n_diffsource']))  unset($row['l10n_diffsource']);

        return array_keys($row);
    }

    /**
     * Label of the field displayed in Backend
     *
     * @param $fieldname
     * @param $row
     * @param $tablename
     * @return string
     */
    protected function getFieldLabel($fieldname, $row, $tablename)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        $return = $GLOBALS['TCA'][$tablename]['columns'][$fieldname]['label'];
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['label']) // override label from type
        ) {
            $return = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['label'];
        }

        if (substr($return, 0, 8) == 'LLL:EXT:') {
            $newReturn = LocalizationUtility::translate($return);
            if (!empty($newReturn)) {
                $return = $newReturn;
            }
        }

        return $return;
    }

    /**
     * Returns true when the field is marked as RTE
     * TODO: make possible to mark like that all fiels
     *
     * @param $fieldname
     * @param $row
     * @param $tablename
     * @return bool
     */
    protected function fieldCanContainHtml($fieldname, $row, $tablename)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        $return = (isset($GLOBALS['TCA'][$tablename]['columns'][$fieldname]['config']['enableRichtext'])) ? $GLOBALS['TCA'][$tablename]['columns'][$fieldname]['config']['enableRichtext'] : false ;
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['config']['enableRichtext']) // override label from type
        ) {
            $return = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['config']['enableRichtext'];
        }

        return $return;
    }

    public function isSlugField($fieldname, $row, $tablename)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        $return = ($GLOBALS['TCA'][$tablename]['columns'][$fieldname]['config']['type'] == 'slug') ? true : false ;
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['config']['type']) // override label from type
        ) {
            $return = ($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['columnsOverrides'][$fieldname]['config']['enableRichtext'] == 'slug') ? true : false ;
        }

        return $return;
    }

    /**
     * @param string $tablename name of the table
     * @param int|array $row UID of entry or the whole row
     */
    public function getExportFields(string $tablename, $row)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        if (empty($row)) {
            return [];
        }

        $return = [];

        $typeArray = [];
        $listOfFields = $this->getListOfTranslatableFields($tablename, $row, $typeArray);

        foreach ($listOfFields as $field) {
            $this->getFieldKeyAndValue($tablename, $field, $row, $return, '', $typeArray);
        }
        return $return;
    }

    public function prepareDataFromRow($uid, $row, $targetLanguage, $tablename, $translatedData = [])
    {
        $return = [];

        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $uid);
        }

        foreach ($row as $fieldname => $value) {
            $notes = [];
            if ($value['slug']) {
                $notes[] = LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:export.field.isSlug');
            }

            $reference = [];

            if (!empty($value['table'])){
                $reference[] = $value['table'];
            }
            if (!empty($value['uid'])){
                $reference[] = $value['uid'];
            }
            if (!empty($value['field'])){
                $reference[] = $value['field'];
            }


            $return[$tablename.'.'.$fieldname] = [
                'default' => $value['value'],
                $targetLanguage => $translatedData[$fieldname] ?? $value['value'],
                '_label' => $value['label'],
                '_html' => $value['html'],
                '_notes' => $notes,
                '_table_reference' => LocalizationUtility::translate('LLL:EXT:hd_translator/Resources/Private/Language/locallang_be.xlf:export.table_reference') . ': ' . implode(':', $reference)
            ];
        }

        return $return;
    }

    /**
     * @param int $uid - UID of page which should be complete exported
     * @param string $targetLanguage - Target language letter
     * @param bool $clean - if false then the whole database entry is exportend,
     * if true, then the database entry is cleaned
     */
    public function getCompleteContentForPage(int $uid = 0, $sourceLanguage = 0, string $targetLanguage = 'en', bool $clean = true)
    {
        $row = $this->getCompleteRow('pages', $uid, $sourceLanguage);

        $realUid = $uid;// PID of other contents
        if ($row['sys_language_uid'] != 0 ) {
            $realUid = (int)$row['l10n_parent']; // The page is just translation;
        }
        if ($clean) {
            $row = $this->getExportFields('pages', $row);
            $output = $this->prepareDataFromRow($realUid, $row, $targetLanguage, 'pages');
        }

        foreach ($this->getAllCompleteteRowsForPid('tt_content', $realUid, $sourceLanguage, $clean) as $contentRowUid => $contentRow) {
            $output = array_merge($output, $this->prepareDataFromRow($contentRowUid, $contentRow, $targetLanguage, 'tt_content'));
        }

        return $output;
    }

    /**
     * @param $uid - UID of the row (needed when $row is already ready for export)
     * @param $row
     * @param $targetLanguage - default would be automatically converted to 'en'
     * @param $tablename
     * @param bool $enableTranslatedData - if false, always the provided $row data are used
     */
    public function exportDatabaseRowToXlf($uid, $row, $targetLanguage, $tablename, $enableTranslatedData = true, $sourceLanguage = 'en')
    {
        if ($targetLanguage == 'default') {
            $targetLanguage = 'en';
        }

        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $uid);
        }

        $data = $this->prepareDataFromRow($uid, $row, $targetLanguage, $tablename);

        $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);

        $output = $xlfService->dataToXlf($data, $targetLanguage, $sourceLanguage);

        return $output;
    }

    /**
     * @param array $data
     * @param int $targetLanguage - sys_language_uid
     */
    public function importIntoDatabase(array $data, int $targetLanguage)
    {
        $data = $this->convertDataToDatabaseTablesArray($data, $targetLanguage);

        if ($data) {
            foreach ($data as $tablename => $rows) {
                foreach ($rows as $l10nParent => $row) {
                    try {
                        $this->importIntoTable($tablename, $l10nParent, $row, $targetLanguage);
                    } catch (\Throwable $th) {
                        self::$importStats['fails']++;
                        self::$importStats['failsMessages'][] = $th->getMessage();
                    }
                }
            }
        }

        $this->cleanRelationshipsAfterImport($targetLanguage);
        /*$this->updateAfterImport[] = [
                    'table' => $parentTableName,
                    'uid' => $rowUid,
                    'field' => $field,
                    'type' => 'updateChildInlinedReferences'
                ];
        */
    }

    public function cleanRelationshipsAfterImport($targetLanguage)
    {
        if (!empty($this->updateAfterImport)) {
            foreach($this->updateAfterImport as $import) {
                switch($import['type']) {
                    case 'updateChildInlinedReferences':
                        $parentTableName = $import['table'];
                        $field = $import['field'];

                        $row = $this->getTranslatedCompleteRow($parentTableName, $import['uid'], $targetLanguage);

                        $foreginTable = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table'] ?? '';
                        if (
                            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table']) // override label from type
                        ) {
                            $foreginTable = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table'];
                        }

                        $foreginField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_field'] ?? '';
                        if (
                            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field']) // override label from type
                        ) {
                            $foreginField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field'];
                        }

                        $foreginTableField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table_field'] ?? '';
                        if (
                            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field']) // override label from type
                        ) {
                            $foreginTableField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field'];
                        }

                        $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_match_fields'] ?? '';
                        if (
                            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields']) // override label from type
                        ) {
                            $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields'];
                        }




                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                        $where = [];

                        if (!empty($foreginTableField)) {
                            $where[] = $queryBuilder->expr()->eq($foreginTableField, $queryBuilder->createNamedParameter($parentTableName));
                        }
                        $langaugeField = 'sys_language_uid';
                        if (!empty($GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'])) {
                            $langaugeField = $GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'];
                        }
                        if (!empty($langaugeField)) {
                            $where[] = $queryBuilder->expr()->eq($langaugeField, $targetLanguage);
                        }
                        if (!empty($foreignMatchFields)) {
                            foreach ($foreignMatchFields as $foreignMatchFieldKey => $foreignMatchFieldValue) {
                                $where[] = $queryBuilder->expr()->eq($foreignMatchFieldKey, $queryBuilder->createNamedParameter($foreignMatchFieldValue));
                            }
                        }

                        $orWhere = [];
                        if (!empty($foreginField)) {
                            // There is original UID
                            if (!empty($import['uid'])) {
                                $orWhere[] = $queryBuilder->expr()->eq($foreginField, $import['uid']);
                            }

                            // Or translated UID
                            if (!empty($row['uid'])) {
                                $orWhere[] = $queryBuilder->expr()->eq($foreginField, $row['uid']);
                            }
                        }

                        $result = $queryBuilder
                            ->select('uid' )
                            ->from($foreginTable)
                            ->orWhere(
                                ...$orWhere
                            )
                            ->andWhere(
                                ...$where
                            )
                            ->executeQuery();

                        $childern = [];
                        while($tempRow = $result->fetchAssociative()) {
                            $childern[] = $tempRow;

                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            if (!self::$onlyDebug) {
                                try {
                                    $temp = $queryBuilder
                                        ->update($foreginTable)
                                        ->set($foreginField, $row['uid'])
                                        ->where(
                                            $queryBuilder->expr()->eq('uid', $tempRow['uid'])
                                        )
                                        ->executeQuery();
                                } catch (\Exception $e) {
                                    self::$importStats['fails']++;
                                    self::$importStats['failsMessages'][] = 'LINE: '.__LINE__.' - ' . $e->getMessage();
                                }
                            }
                        }
                        // update parent inline field => if INT then amount of children, if VARCHAR then list of uids
                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($parentTableName)->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                        if (!self::$onlyDebug) {
                            try {
                            $temp = $queryBuilder
                                ->update($parentTableName)
                                ->set($field, count($childern))
                                ->where(
                                    $queryBuilder->expr()->eq('uid', $row['uid'])
                                )
                                ->executeQuery();
                            } catch (\Exception $e) {
                                self::$importStats['fails']++;
                                self::$importStats['failsMessages'][] = 'LINE: '.__LINE__.' - ' . $e->getMessage();
                            }
                        }
                        break;
                    case 'updateChildInlinedReferencesFlexform':
                        $translatedRow = self::$databaseEntriesTranslated[$import['parentTable']][$import['parentUid']];

                        $foreginTable = $import['config']['foreign_table'] ?? '';


                        $foreginField = $import['config']['foreign_field'] ?? '';

                        $foreginTableField = $import['config']['foreign_table_field'] ?? '';

                        $foreignMatchFields = $import['config']['foreign_match_fields'] ?? '';

                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                        $where = [];

                        if (!empty($foreginTableField)) {
                            $where[] = $queryBuilder->expr()->eq($foreginTableField, $queryBuilder->createNamedParameter($import['parentTable']));
                        }
                        $langaugeField = 'sys_language_uid';
                        if (!empty($GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'])) {
                            $langaugeField = $GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'];
                        }
                        if (!empty($langaugeField)) {
                            $where[] = $queryBuilder->expr()->eq($langaugeField, $targetLanguage);
                        }
                        if (!empty($foreignMatchFields)) {
                            foreach ($foreignMatchFields as $foreignMatchFieldKey => $foreignMatchFieldValue) {
                                $where[] = $queryBuilder->expr()->eq($foreignMatchFieldKey, $queryBuilder->createNamedParameter($foreignMatchFieldValue));
                            }
                        }

                        $orWhere = [];
                        if (!empty($foreginField)) {
                            // Or translated UID
                            if (!empty($translatedRow['uid'])) {
                                $orWhere[] = $queryBuilder->expr()->eq($foreginField, $translatedRow['uid']);
                            }
                        }

                        $temp = $queryBuilder
                            ->count('uid' )
                            ->from($foreginTable);
                        if (!empty($orWhere)) {
                            $temp = $temp->orWhere(
                                ...$orWhere
                            );
                        }
                        if (!empty($where)) {
                            $temp = $temp->andWhere(
                                ...$where
                            );
                        }

                        $result = $temp->executeQuery();

                        // check if copies are needed
                        if ($result->fetchOne() == 0) {
                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                            $where = [];
                            if (!empty($foreginTableField)) {
                                $where[] = $queryBuilder->expr()->eq($foreginTableField, $queryBuilder->createNamedParameter($import['parentTable']));
                            }
                            $langaugeField = 'sys_language_uid';
                            if (!empty($GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'])) {
                                $langaugeField = $GLOBALS['TCA'][$foreginTableField]['ctrl']['languageField'];
                            }
                            if (!empty($langaugeField)) {
                                $where[] = $queryBuilder->expr()->eq($langaugeField, 0);
                            }
                            if (!empty($foreignMatchFields)) {
                                foreach ($foreignMatchFields as $foreignMatchFieldKey => $foreignMatchFieldValue) {
//                                    $where[] = $queryBuilder->expr()->eq($foreignMatchFieldKey, $queryBuilder->createNamedParameter($foreignMatchFieldValue));
                                }
                            }

                            $orWhere = [];
                            if (!empty($foreginField)) {
                                if (!empty($import['parentUid'])) {
                                    $orWhere[] = $queryBuilder->expr()->eq($foreginField, $import['parentUid']);
                                }
                                if (!empty($translatedRow['uid'])) {
                                    $orWhere[] = $queryBuilder->expr()->eq($foreginField, $translatedRow['uid']);
                                }
                            }

                            $temp = $queryBuilder
                                ->select('*' )
                                ->from($foreginTable);
                            if (!empty($orWhere)) {
                                $temp = $temp->orWhere(
                                    ...$orWhere
                                );
                            }
                            if (!empty($where)) {
                                $temp = $temp->andWhere(
                                    ...$where
                                );
                            }

                            $result = $temp->executeQuery();
                            while($row = $result->fetchAssociative()) {
                                if ($translatedRow['uid']) {
                                    unset($row['uid']);
                                    $row['tstamp'] = $row['crdate'] = time();
                                    $row['sys_language_uid'] = $targetLanguage;
                                    $row[$foreginField] = $translatedRow['uid'];

                                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                                    if (!self::$onlyDebug) {
                                        $affectedRows = $queryBuilder
                                            ->insert($foreginTable)
                                            ->values($row)
                                            ->executeQuery();
                                        self::$importStats['inserts']++;
                                    }
                                } else {
//                                    DebuggerUtility::var_dump($translatedRow);
                                }
                            }
                        }

                        break;
                }
            }

        }

        if (!empty($this->updateMmRelations)) {
            foreach($this->updateMmRelations as $mmRelation) {
                if (empty(self::$databaseEntriesTranslated[$mmRelation['foreginTable']][$mmRelation['local_uid']])) {
                    self::$databaseEntriesTranslated[$mmRelation['foreginTable']][$mmRelation['local_uid']] = $this->getTranslatedCompleteRow($mmRelation['foreginTable'], $mmRelation['local_uid'], $targetLanguage);
                }

                if (!empty(self::$databaseEntriesTranslated[$mmRelation['foreginTable']][$mmRelation['local_uid']])) {
                    // is the mm table is opposite?
                    $localFieldMm = 'uid_local';
                    $foreginFieldMm = 'uid_foreign';
                    if ($mmRelation['MM_opposite_field'] != false) {
                        $localFieldMm = 'uid_foreign';
                        $foreginFieldMm = 'uid_local';
                    }
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($mmRelation['mm_table'])->createQueryBuilder();
                    $queryBuilder->getRestrictions()->removeAll();

                    $result = $queryBuilder
                        ->select('*')
                        ->from($mmRelation['mm_table'])
                        ->where(
                            $queryBuilder->expr()->eq($localFieldMm, $mmRelation['local_uid'])
                        )
                        ->executeQuery();

                    $newUid = self::$databaseEntriesTranslated[$mmRelation['foreginTable']][$mmRelation['local_uid']]['uid'];
                    while($newUid && $row = $result->fetchAssociative()) {

                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($mmRelation['mm_table'])->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll();
                        $result2 = $queryBuilder
                            ->select('*')
                            ->from($mmRelation['mm_table'])
                            ->where(
                                $queryBuilder->expr()->eq($localFieldMm, $newUid),
                                $queryBuilder->expr()->eq($foreginFieldMm, $row['uid_foreign'])
                            )
                            ->executeQuery();
                        if (!$result2->fetchAssociative()) {
                            if (!self::$onlyDebug) {
                                $row[$localFieldMm] = $newUid;
                                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($mmRelation['mm_table'])->createQueryBuilder();
                                $queryBuilder->getRestrictions()->removeAll();
                                if (isset($row['uid'])) {
                                    unset($row['uid']);
                                }
                                try {
                                    $temp = $queryBuilder
                                        ->insert($mmRelation['mm_table'])
                                        ->values(
                                            $row
                                        )
                                        ->executeQuery();
                                } catch (\Exception $e) {

                                }
                            }
                        }
                    }
                }
            }
        }


        foreach (self::$databaseEntriesOriginal as $tablename => $items) {
            foreach ($items as $id => $data) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->createQueryBuilder();
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $result = $queryBuilder
                    ->select('*')
                    ->from('sys_file_reference')
                    ->where(
                        $queryBuilder->expr()->eq('sys_language_uid', 0),
                        $queryBuilder->expr()->eq('uid_foreign', $id),
                        $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($tablename))
                    )
                    ->executeQuery();


                while($defaultLanguageRow = $result->fetchAssociative()) {
                    if (!self::$databaseEntriesTranslated[$tablename][$id]['uid']) {
                        continue;
                    }
                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->createQueryBuilder();
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                    $where = [
                        $queryBuilder->expr()->eq('uid_foreign', self::$databaseEntriesTranslated[$tablename][$id]['uid']),
                    ];

                    // If issue with languages for sys_file_references
                    if (false) {
                        $where[] = $queryBuilder->expr()->eq('sys_language_uid', $targetLanguage);
                    }

                    $result2 = $queryBuilder
                        ->select('*')
                        ->from('sys_file_reference')
                        ->where(
                            ...$where
                        )
                        ->executeQuery();
                    $output = $result2->fetchAssociative();

                    if (!$output) {
                        $defaultLanguageRow['sys_language_uid'] = $targetLanguage;
                        $defaultLanguageRow['l10n_parent'] = $defaultLanguageRow['uid'];
                        $defaultLanguageRow['uid_foreign'] = self::$databaseEntriesTranslated[$tablename][$id]['uid'];
                        $defaultLanguageRow['tstamp'] = $defaultLanguageRow['crdate'] = time();

                        unset($defaultLanguageRow['uid']);
                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                        $queryBuilder
                            ->insert('sys_file_reference')
                            ->values($defaultLanguageRow)
                            ->executeQuery();
                        self::$importStats['inserts']++;
                    }
                }
            }
        }

    }

    public function checkFlexformInlinedFields($targetLanguage, $tablename, $key, $row, $originalRow)
    {
        $fieldConfig = $GLOBALS['TCA'][$tablename]['columns'][$key]['config'];
        if (!empty($fieldConfig['ds_pointerField'])) {
            $pointers = GeneralUtility::trimExplode(',', $fieldConfig['ds_pointerField']);
            $noneUsed = true;
            foreach ($pointers as $pointer) {
                if (!empty($fieldConfig['ds'][$originalRow[$pointer]])) {
                    $this->checkFlexformInlinedFieldsParseFlexform($targetLanguage, $tablename, $key, $row, $fieldConfig['ds'][$originalRow[$pointer]], $originalRow);
                    $noneUsed = false;
                    break;
                } else if (!empty($fieldConfig['ds']['*,'.$originalRow[$pointer]])) {
                    $this->checkFlexformInlinedFieldsParseFlexform($targetLanguage, $tablename, $key, $row, $fieldConfig['ds']['*,'.$originalRow[$pointer]], $originalRow);
                    $noneUsed = false;
                    break;
                }
            }

            if ($noneUsed && !empty($fieldConfig['ds']['default'])) {
                $this->checkFlexformInlinedFieldsParseFlexform($targetLanguage, $tablename, $key, $row, $fieldConfig['ds']['default'], $originalRow);
            }
        }
    }

    public function checkFlexformInlinedFieldsParseFlexform($targetLanguage, $tablename, $key, $row, $fleformDefinition, $originalRow)
    {
        $flexFormArray = GeneralUtility::xml2array($fleformDefinition);
        if (!empty($row[$key]['data']) && !empty($flexFormArray)) {
            // Default settings doesn't have sheet, so setup default sheet
            if (!isset($flexFormArray['sheets'])) {
                $flexFormArray['sheets'] = [$flexFormArray];
            }

            foreach ($flexFormArray['sheets'] as $sheetName => $sheetDefinition) {
                if (!empty($sheetDefinition['ROOT']['el'])) {
                    foreach ($sheetDefinition['ROOT']['el'] as $name => $config) {
                        // Default settings can ommit TCEforms part
                        $tempConfig = false;
                        if (!empty($config['config']['type'])) {
                            $tempConfig = $config;
                        } elseif (!empty($config['TCEforms']['config']['type'])) {
                            $tempConfig = $config['TCEforms']['config'];
                        }

                        if (!empty($tempConfig['type'])) {
                            switch ($tempConfig['type']) {
                                case 'inline':
                                    $this->updateAfterImport[$tablename . '-' . $originalRow['uid'] . '-' . $row[$key] . '-' . count($this->updateAfterImport) . '-updateFlexFormReferences'] = [
                                        'config' => $tempConfig,
                                        'parentUid' => $originalRow['uid'],
                                        'parentTable' => $tablename,
                                        'type' => 'updateChildInlinedReferencesFlexform'
                                    ];
                                    break;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $tablename
     * @param $l10nParent
     * @param $row
     */
    public function importIntoTable($tablename, $l10nParent, $row, $targetLanguage)
    {
        if (empty(self::$databaseEntriesOriginal[$tablename][$l10nParent])) {
            self::$databaseEntriesOriginal[$tablename][$l10nParent] = $this->getCompleteRow($tablename, $l10nParent);
        }

        // convert felxform array into string
        foreach($row as $key => $value) {
            if (is_array($value)) {
                $flexFormTools = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools::class);
                $this->checkFlexformInlinedFields($targetLanguage, $tablename, $key, $row, self::$databaseEntriesOriginal[$tablename][$l10nParent]);
                $row[$key] = $flexFormTools->flexArray2Xml($row[$key], true);
            }
        }

        $translatedRow = $this->getTranslatedCompleteRow($tablename, $l10nParent, $targetLanguage);

        if (!empty($translatedRow)) {
            $disabledFieldsForUpdate = $this->getFieldsDisabledFromUpdate($tablename, self::$databaseEntriesOriginal[$tablename][$l10nParent]);

            // Only Update row
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            if (!self::$onlyDebug) {
                try {
                    $temp = $queryBuilder
                        ->update($tablename)
                        ->where(
                            $queryBuilder->expr()->eq('uid', $translatedRow['uid'])
                        );

                    foreach ($row as $key => $value) {
                        if (!in_array($key, $disabledFieldsForUpdate)) {
                            $temp = $temp->set($key, $value);
                        }
                    }

                    $temp->executeQuery();

                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                    $resultTemp = $queryBuilder
                        ->select('*')
                        ->from($tablename)
                        ->where(
                            $queryBuilder->expr()->eq('uid', $translatedRow['uid'])
                        )
                        ->executeQuery();
                    $rowTemp = $resultTemp->fetchAssociative();

                    if ($rowTemp) {
                        self::$databaseEntriesTranslated[$tablename][$l10nParent] = $rowTemp;
                    }

                } catch (\Exception $e) {
                    self::$importStats['fails']++;
                    self::$importStats['failsMessages'][] = $e->getMessage();
                }
            }

            self::$importStats['updates']++;
        } else {
            // Import whole data
            $return = $this->insertIntoTable($tablename, $l10nParent, $row, $targetLanguage);
            if ($return) {
                self::$importStats['inserts']++;
            } else {
                self::$importStats['fails']++;
            }
        }
    }

    public function insertIntoTable($tablename, $l10nParent, $row, $targetLanguage)
    {
        if (empty(self::$databaseEntriesOriginal[$tablename][$l10nParent])) {
            self::$databaseEntriesOriginal[$tablename][$l10nParent] = $this->getCompleteRow($tablename, $l10nParent);
        }

        if (empty(self::$databaseEntriesOriginal[$tablename][$l10nParent])) {
            self::$importStats['failsMessages'][] = 'Default language translation doesn\' exists : '.$tablename .':'.$l10nParent;
            return false;
        }

        $typeArray = [];
        $listOfFields = $this->getListOfTranslatableFields($tablename, self::$databaseEntriesOriginal[$tablename][$l10nParent], $typeArray);

        foreach (self::$databaseEntriesOriginal[$tablename][$l10nParent] as $key => $parentValue) {
            // if colmun is not exisitn then shouldn't be synced
            if (
                !in_array($key, $listOfFields)
                && !empty($GLOBALS['TCA'][$tablename]['columns'][$key])
                && (
                    empty($GLOBALS['TCA'][$tablename]['columns'][$key]['l10n_mode'])
                    || $GLOBALS['TCA'][$tablename]['columns'][$key]['l10n_mode'] != 'exclude'
                    || ($tablename == 'tt_content' && $key == 'CType')
                )
            ) {
                if (
                    !empty($GLOBALS['TCA'][$tablename]['columns'][$key]['config']['type'])
                    && (
                        $GLOBALS['TCA'][$tablename]['columns'][$key]['config']['type'] == 'inline'
                    )
                ) {
                    $this->duplicateInlineData($tablename, $l10nParent, $key, self::$databaseEntriesOriginal[$tablename][$l10nParent], $targetLanguage);
                } else {
                    if (
                        $GLOBALS['TCA'][$tablename]['columns'][$key]['config']['type'] == 'select'
                        && !empty($GLOBALS['TCA'][$tablename]['columns'][$key]['config']['MM'])
                    ) {
                        $this->updateMmRelations[] = [
                            'foreginTable' => $tablename,
                            'mm_table' => $GLOBALS['TCA'][$tablename]['columns'][$key]['config']['MM'],
                            'local_uid' => $l10nParent,
                            'MM_opposite_field' => $GLOBALS['TCA'][$tablename]['columns'][$key]['config']['MM_opposite_field'] ?? false,
                        ];
                    }
                    $row[$key] = $parentValue;
                }
            } else {
                // Default values from original language if the value is not set
                $disabledKeys = ['uid'];
                if (
                    !isset($row[$key])
                    && !in_array($key, $disabledKeys)
                    && (
                        empty($GLOBALS['TCA'][$tablename]['columns'][$key]['l10n_mode'])
                        || $GLOBALS['TCA'][$tablename]['columns'][$key]['l10n_mode'] != 'exclude'
                    )
                ) {
                    $row[$key] = $parentValue;
                }
            }
        }

        $parentUidField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'])) {
            $parentUidField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'];
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['translationSource'])) {
            $row[$GLOBALS['TCA'][$tablename]['ctrl']['translationSource']] = $l10nParent;
        }
        $langaugeField = 'sys_language_uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            $langaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'];
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['crdate'])) {
            $row[$GLOBALS['TCA'][$tablename]['ctrl']['crdate']] = time();
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['tstamp'])) {
            $row[$GLOBALS['TCA'][$tablename]['ctrl']['tstamp']] = time();
        }
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['sortby'])) {
            $row[$GLOBALS['TCA'][$tablename]['ctrl']['sortby']] = self::$databaseEntriesOriginal[$tablename][$l10nParent][$GLOBALS['TCA'][$tablename]['ctrl']['sortby']];
        }
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigDiffSourceField'])
            && !empty(self::$databaseEntriesOriginal[$tablename][$l10nParent])
        ) {
            $row[$GLOBALS['TCA'][$tablename]['ctrl']['transOrigDiffSourceField']] = json_encode(self::$databaseEntriesOriginal[$tablename][$l10nParent]);
        }

        $row['pid'] = self::$databaseEntriesOriginal[$tablename][$l10nParent]['pid'];
//        if ($tablename == 'pages') {
//            $row['pid'] = self::$databaseEntriesOriginal[$tablename][$l10nParent]['uid'];
//        }
        $row[$parentUidField] = $l10nParent;
        $row[$langaugeField] = $targetLanguage;

        foreach ($row as $tempKey => $tempValue) {
            if ($tempValue === true) {
                unset($row[$tempKey]);
            }
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!self::$onlyDebug) {
            $temp = $queryBuilder
                ->insert($tablename)
                ->values(
                    $row
                )
                ->executeQuery();

            $lastUid = $queryBuilder->getConnection()->lastInsertId();

            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $resultTemp = $queryBuilder
                ->select('*')
                ->from($tablename)
                ->where(
                    $queryBuilder->expr()->eq('uid', $lastUid)
                )
                ->executeQuery();
            $rowTemp = $resultTemp->fetchAssociative();

            if ($rowTemp) {
                self::$databaseEntriesTranslated[$tablename][$l10nParent] = $rowTemp;
            }
        }


        return true;
    }

    protected function duplicateInlineData($parentTableName, $l10nParent, $field, $l10nParentRow, $targetLanguage)
    {
        $mmTable = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['MM'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['MM']) // override label from type
        ) {
            $mmTable = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['MM'];
        }

        $foreginTable = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table']) // override label from type
        ) {
            $foreginTable = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table'];
        }

        $foreginField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_field'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field']) // override label from type
        ) {
            $foreginField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field'];
        }

        $foreginTableField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table_field'] ?? '';
        if (
            !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
            && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field']) // override label from type
        ) {
            $foreginTableField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field'];
        }

        if (!empty($GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_match_fields'])) {
            $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_match_fields'];
            if (
                !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields']) // override label from type
            ) {
                $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields'];
            }
        }

        if (empty($foreignMatchFields)) {
            $foreignMatchFields = [];
        }

        if (!empty($mmTable)) {

        } else {
            $rows = $this->getCompleteInlinedRows($foreginTable, $l10nParent, $foreginField, $foreginTableField, $parentTableName, $foreignMatchFields);

            if ($rows) {
                foreach ($rows as $row) {
                    // fix mm relations
                    foreach ($row as $rowField => $rowValue) {
                        $mmTable = $GLOBALS['TCA'][$foreginTable]['columns'][$rowField]['config']['MM'] ?? '';

                        if (!empty($mmTable)) {
                            $this->updateMmRelations[] = [
                                'foreginTable' => $foreginTable,
                                'mm_table' => $mmTable,
                                'local_uid' => $row['uid'],
                                'MM_opposite_field' => $GLOBALS['TCA'][$foreginTable]['columns'][$rowField]['config']['MM_opposite_field'] ?? false,
                            ];
                        }
                    }

                    $langaugeField = 'sys_language_uid';
                    if (!empty($GLOBALS['TCA'][$foreginTable]['ctrl']['languageField'])) {
                        $langaugeField = $GLOBALS['TCA'][$foreginTable]['ctrl']['languageField'];
                    }
                    $row[$langaugeField] = $targetLanguage;

                    if (!empty($GLOBALS['TCA'][$foreginTable]['ctrl']['transOrigPointerField'])) {
                        $row[$GLOBALS['TCA'][$foreginTable]['ctrl']['transOrigPointerField']] = $row['uid'];
                    }

                    if (
                        !empty($GLOBALS['TCA'][$foreginTable]['ctrl']['transOrigDiffSourceField'])
                        && !empty(self::$databaseEntriesOriginal[$foreginTable][$row['uid']])
                    ) {
                        $row[$GLOBALS['TCA'][$foreginTable]['ctrl']['transOrigDiffSourceField']] = json_encode(self::$databaseEntriesOriginal[$foreginTable][$row['uid']]);
                    }

                    if ($row['uid']) {
                        unset($row['uid']);
                    }

                    $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                    $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

                    if (!self::$onlyDebug) {
                        $temp = $queryBuilder
                            ->insert($foreginTable)
                            ->values(
                                $row
                            )
                            ->executeQuery();
                    }

                    $this->updateAfterImport[$parentTableName . '-' . $l10nParent . '-' . $field . '-updateChildInlinedReferences'] = [
                        'table' => $parentTableName,
                        'uid' => $l10nParent,
                        'field' => $field,
                        'type' => 'updateChildInlinedReferences'
                    ];
                }
            }
        }
    }

    /**
     * @param array $data
     */
    public function convertDataToDatabaseTablesArray(array $data, $targetLanguage)
    {
        $return = [];

        foreach ($data as $key => $value) {
            $this->databaseTableArrayRecursive($key, $value, $return, $targetLanguage);
        }

        return $return;
    }

    public function recursiveUpdateOfArrayByGivenKey(&$return, array $key, $value)
    {


        $tempName = [];
        $missingKeys = $key;

        for ($i = 0; $i < count($key); $i++) {
            $part = $key[$i];
            $tempName[] = $part;
            unset($missingKeys[$i]);

            if (isset($return[implode('.',$tempName)]['el'])) {
                // repetable value
                $i++;
                unset($missingKeys[$i]);
                if (count($missingKeys) > 0) {
                    if (count($missingKeys) == 1) {
                        if (is_string($return[implode('.', $tempName)]['el'][$key[$i]])) {
                            unset($return[implode('.', $tempName)]['el'][$key[$i]]);
                            $return[implode('.', $tempName)]['el'][$key[$i]][$key[$i + 1]]['vDEF'] = $value;
                            return;
                        }
                    }

                    $this->recursiveUpdateOfArrayByGivenKey($return[implode('.', $tempName)]['el'][$key[$i]], explode('.', implode('.', $missingKeys)), $value);
                } else {
                    $return[implode('.', $tempName)]['el'][$key[$i]]['vDEF'] = $value;
                }
            } else if (isset($return[implode('.',$tempName)]['vDEF'])) {
                // Simple value
                $return[implode('.',$tempName)]['vDEF'] = $value;
            }
        }
    }

    /**
     * @param $key
     * @param $value
     * @param $return
     */
    protected function databaseTableArrayRecursive($key, $value, &$return, $targetLanguage)
    {
        $tempKeys = explode('.', $key);
        if (count($tempKeys) == 3) {
            // 0 tablename, 1 uid, 2 field
            $return[$tempKeys[0]][$tempKeys[1]][$tempKeys[2]] = $value;
        } else if(count($tempKeys) > 3) {
            $parentTableName = $tempKeys[0];
            $rowUid = $tempKeys[1];
            $field = $tempKeys[2];

            if (empty(self::$databaseEntriesOriginal[$parentTableName][$rowUid])) {
                self::$databaseEntriesOriginal[$parentTableName][$rowUid] = $this->getCompleteRow($parentTableName, $rowUid);
            }

            // if $tempKeys[3] is numeric, then the items are subitems, otherwise it seems like flexform
            if ((int) $tempKeys[3] == 0) {
                if (empty($return[$parentTableName][$rowUid][$field])) {
                    $return[$parentTableName][$rowUid][$field] = GeneralUtility::xml2array(strval(self::$databaseEntriesOriginal[$parentTableName][$rowUid][$field]));

                    if (empty(self::$databaseEntriesTranslated[$parentTableName][$rowUid])) {
                        self::$databaseEntriesTranslated[$parentTableName][$rowUid] = $this->getTranslatedCompleteRow($parentTableName, $rowUid, $targetLanguage);

                        if (!empty(self::$databaseEntriesTranslated[$parentTableName][$rowUid])){
                            $return[$parentTableName][$rowUid][$field] = GeneralUtility::xml2array(strval(self::$databaseEntriesTranslated[$parentTableName][$rowUid][$field]));
                        }
                    }
                }

                unset($tempKeys[0]);
                unset($tempKeys[1]);
                unset($tempKeys[2]);
                $keyName = implode('.',$tempKeys);
                $tabName = 'sDEF';
                if (!empty($return[$parentTableName][$rowUid][$field]['data']) && count($return[$parentTableName][$rowUid][$field]['data']) == 1) {
                    $tabName = key($return[$parentTableName][$rowUid][$field]['data']);
                }

                if (!empty($return[$parentTableName][$rowUid][$field]['data'])) { // malformwd flexform issue
                    foreach ($return[$parentTableName][$rowUid][$field]['data'] as $sheetName => $sheetData) {
                        if ($sheetData['lDEF']) {
                            if (in_array($keyName, array_keys($sheetData['lDEF']))) {
                                $tabName = $sheetName;
                                break;
                            }
                        }
                    }
                    $this->recursiveUpdateOfArrayByGivenKey($return[$parentTableName][$rowUid][$field]['data'][$tabName]['lDEF'], explode('.', implode('.', $tempKeys)), $value);
                }
            } else {
                // disable from sync
                $return[$tempKeys[0]][$tempKeys[1]][$tempKeys[2]] = true;

                $uidOfChild = $tempKeys[3];

                $row = self::$databaseEntriesOriginal[$parentTableName][$rowUid];
                if ($row) {
                    switch ($GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['type']) {
                        case 'file':
                            $foreginTable = 'sys_file_reference';
//                            $foreginField = 'uid_foreign';
//                            $foreginTableField = 'tablenames';
//                            $fieldField = 'fieldname';

                            $tempKeys[2] = $foreginTable;
                            unset($tempKeys[0]);
                            unset($tempKeys[1]);

                            $this->databaseTableArrayRecursive(implode('.', $tempKeys), $value, $return, $targetLanguage);

                            // Update parent table field to store amount of child or coma separated list
                            $this->updateAfterImport[$parentTableName . '-' . $rowUid . '-' . $field . '-updateChildInlinedReferences'] = [
                                'table' => $parentTableName,
                                'uid' => $rowUid,
                                'field' => $field,
                                'type' => 'updateChildInlinedReferences'
                            ];

                        case 'inline':
                            $foreginTable = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table'];
                            if (
                                !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                                && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                                && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table']) // override label from type
                            ) {
                                $foreginTable = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table'];
                            }

                            $foreginField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_field'] ?? '';
                            if (
                                !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                                && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                                && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field']) // override label from type
                            ) {
                                $foreginField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_field'];
                            }

                            $foreginTableField = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_table_field'] ?? '';
                            if (
                                !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                                && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                                && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field']) // override label from type
                            ) {
                                $foreginTableField = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_table_field'];
                            }

                            $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['columns'][$field]['config']['foreign_match_fields'] ?? '';
                            if (
                                !empty($GLOBALS['TCA'][$parentTableName]['ctrl']['type']) // type field is defined
                                && isset($row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]) // row has this field
                                && !empty($GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields']) // override label from type
                            ) {
                                $foreignMatchFields = $GLOBALS['TCA'][$parentTableName]['types'][$row[$GLOBALS['TCA'][$parentTableName]['ctrl']['type']]]['columnsOverrides'][$field]['config']['foreign_match_fields'];
                            }

                            if (!empty($foreginField)) {
                                $return[$foreginTable][$uidOfChild][$foreginField] = $rowUid;
                            }

                            if (!empty($foreginTableField)) {
                                $return[$foreginTable][$uidOfChild][$foreginTableField] = $parentTableName;
                            }

                            if (!empty($foreignMatchFields)) {
                                foreach ($foreignMatchFields as $matchKey => $matchValue) {
                                    $return[$foreginTable][$uidOfChild][$matchKey] = $matchValue;
                                }
                            }

                            $tempKeys[2] = $foreginTable;
                            unset($tempKeys[0]);
                            unset($tempKeys[1]);

                            $this->databaseTableArrayRecursive(implode('.', $tempKeys), $value, $return, $targetLanguage);

                            // Update parent table field to store amount of child or coma separated list
                            $this->updateAfterImport[$parentTableName . '-' . $rowUid . '-' . $field . '-updateChildInlinedReferences'] = [
                                'table' => $parentTableName,
                                'uid' => $rowUid,
                                'field' => $field,
                                'type' => 'updateChildInlinedReferences'
                            ];
                            break;
                    }
                }
            }
        }

    }
}

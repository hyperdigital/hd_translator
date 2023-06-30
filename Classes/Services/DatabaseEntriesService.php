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
    protected $updateMmRelations = [];

    /**
     * @var bool this will disable inserting or updating data
     */
    public static $onlyDebug = false;


    public static $importStats = ['updates' => 0, 'inserts' => 0, 'fails' => 0];
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
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]) // types has value for this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]['translator_export'])
        ) {
            $typeArray = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]];
        } else {
            if (isset($GLOBALS['TCA'][$tablename]['types']['1']['translator_export'])) {
                $typeArray = $GLOBALS['TCA'][$tablename]['types']['1'];
            }
        }

        $typeArrayReturn = $typeArray;

        if (isset($typeArray['translator_export'])) {
            $listOfFields = GeneralUtility::trimExplode(',',$typeArray['translator_export']);
        }  else {
            $listOfFields = $this->getListOfFieldsFromRow($tablename, $row);
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

        $queryGenerator = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\QueryGenerator::class);

        $subpages = $queryGenerator->getTreeList($parentPid, 9999);
        if ($subpages){
            $subpages = GeneralUtility::trimExplode(',', $subpages);
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
    public function getCompleteRow(string $tablename, int $rowUid)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->where(
                $queryBuilder->expr()->eq('uid', $rowUid)
            )
            ->execute();

        $row = $result->fetchAssociative();
        //setup default lanugage uid
        $parentUidField = 'l10n_parent';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'])) {
            $parentUidField = $GLOBALS['TCA'][$tablename]['ctrl']['transOrigPointerField'];
        }
        $langaugeField = 'sys_language_uid';
        if (!empty($GLOBALS['TCA'][$tablename]['ctrl']['languageField'])) {
            $langaugeField = $GLOBALS['TCA'][$tablename]['ctrl']['languageField'];
        }
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
            ->execute();

        $row = $result->fetchAssociative();

        return $row;
    }

    /**
     * @param string $tablename name of the table
     * @param int $pid - PID where the rows are stored (if lower then 0 then it's over the whole database)
     * @param bool $clean - return cleaned rows
     */
    public function getAllComplteteRowsForPid(string $tablename, int $pid, bool $clean = false)
    {
        $return = [];
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $where = [];
        if ($pid < 0) {
            $where[] = $queryBuilder->expr()->gt('pid', -1);
        } else {
            $where[] = $queryBuilder->expr()->eq('pid', $pid);
        }

        $result = $queryBuilder
            ->select('*')
            ->from($tablename)
            ->where(
                ...$where
            )
            ->execute();

        while($row = $result->fetchAssociative()) {
            if ($clean) {
                $return[] = $this->getExportFields($tablename, $row);
            } else {
                $return[] = $row;
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
            ->execute();

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
                    $return[$specialFieldNameOutput]['value'] = $row[$field] ?? '';
                    $return[$specialFieldNameOutput]['label'] = $this->getFieldLabel($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['html'] = $this->fieldCanContainHtml($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['slug'] = $this->isSlugField($field, $row, $tablename);
                    $return[$specialFieldNameOutput]['table'] = $tablename;
                    $return[$specialFieldNameOutput]['uid'] = $row['uid'] ?? '';
                    $return[$specialFieldNameOutput]['field'] = $field ?? '';
                    break;
                case 'inline':
                    $this->getInlinedRowsFieldKeyAndValue($tablename, $field, $row, $return, $specialFieldNameOutput);
                    break;
                case 'flex':
                    $limitedFields = [];
                    if (isset($typeArray['translator_export_column'][$field])) {
                        $limitedFields = GeneralUtility::trimExplode(',', $typeArray['translator_export_column'][$field]);
                    }
                    $this->getFlexformKeysAndValues($tablename, $field, $row, $return, $field, $limitedFields, $typeArray);
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
    protected function getFlexformKeysAndValues(string $tablename, string $field, array $row, array &$return, string $specialFieldNameOutput, array $limitedFields, $typeArray = [])
    {
        $flexString = $row[$field];
        $data = $this->flexFormService
            ->convertFlexFormContentToArray(strval($flexString));

        foreach ($data as $key => $value) {
            $subarrayName = $row['uid'].'.'.$specialFieldNameOutput.'.'.$key;
            $this->subarrayToKeyValues($tablename, $field, $row['uid'], $return, $subarrayName, $value, $limitedFields, $typeArray);
        }
    }

    protected function subarrayToKeyValues(string $tablename, string $field, $rowUid, &$return, $subarrayName, $value, $limitedFields, $typeArray = [])
    {
        $subarrayFieldName = substr($subarrayName, strlen($rowUid.'.'.$field.'.')); // full name with the parent field - used in export settings
        if (is_string($value)) {
            if (empty($limitedFields) || in_array($subarrayFieldName, $limitedFields)) {
                $return[$subarrayName]['value'] = $value ?? '';
                $return[$subarrayName]['label'] = $subarrayName; // TODO
                $return[$subarrayName]['html'] = false;// TODO
                $return[$subarrayName]['slug'] = false; // TODO
                $return[$subarrayName]['table'] = $tablename;
                $return[$subarrayName]['uid'] = $rowUid ?? '';
                $return[$subarrayName]['field'] = $subarrayName ?? '';
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
                        $this->subarrayToKeyValues($tablename, $field, $rowUid, $return, $newKey, $newValue, $limitedFields, $typeArray);
                    }
                } else {
                    $newKey = $subarrayName . '.' . $newKey;
                    $this->subarrayToKeyValues($tablename, $field, $rowUid, $return, $newKey, $newValue, $limitedFields, $typeArray);
                }
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
        /**
         * @param string $tablename
         * @param int $parentUid
         * @param string $foreignField
         * @param string $foreignSortby
         * @param string $foreignTableField
         * @param array $foreignMatchFields
         * @return mixed
         */

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
    public function getCompleteContentForPage(int $uid = 0, string $targetLanguage = 'en', bool $clean = true)
    {
        $row = $this->getCompleteRow('pages', $uid);

        $realUid = $uid;// PID of other contents
        if ($row['sys_language_uid'] != 0 ) {
            $realUid = $row['l10n_parent']; // The page is just translation;
        }
        if ($clean) {
            $row = $this->getExportFields('pages', $row);
            $output = $this->prepareDataFromRow((int) $row['uid'], $row, $targetLanguage, 'pages');
        }

        foreach ($this->getAllComplteteRowsForPid('tt_content', $realUid, $clean) as $contentRow) {
            $output = array_merge($output, $this->prepareDataFromRow((int) $contentRow['uid'], $contentRow, $targetLanguage, 'tt_content'));
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
    public function exportDatabaseRowToXlf($uid, $row, $targetLanguage, $tablename, $enableTranslatedData = true)
    {
        if ($targetLanguage == 'default') {
            $targetLanguage = 'en';
        }

        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $uid);
        }

        $data = $this->prepareDataFromRow($uid, $row, $targetLanguage, $tablename);

        $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);

        $output = $xlfService->dataToXlf($data, $targetLanguage);

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
                    $this->importIntoTable($tablename, $l10nParent, $row, $targetLanguage);
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
                            ->execute();

                        $childern = [];
                        while($tempRow = $result->fetchAssociative()) {
                            $childern[] = $tempRow;

                            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($foreginTable)->createQueryBuilder();
                            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                            if (!self::$onlyDebug) {
                                $temp = $queryBuilder
                                    ->update($foreginTable)
                                    ->set($foreginField, $row['uid'])
                                    ->where(
                                        $queryBuilder->expr()->eq('uid', $tempRow['uid'])
                                    )
                                    ->execute();
                            }
                        }
                        // update parent inline field => if INT then amount of children, if VARCHAR then list of uids
                        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($parentTableName)->createQueryBuilder();
                        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                        if (!self::$onlyDebug) {
                            $temp = $queryBuilder
                                ->update($parentTableName)
                                ->set($field, count($childern))
                                ->where(
                                    $queryBuilder->expr()->eq('uid', $row['uid'])
                                )
                                ->execute();
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
                        ->execute();

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
                            ->execute();
                        if (!$result2->fetchAssociative()) {
                            if (!self::$onlyDebug) {
                                $row[$localFieldMm] = $newUid;
                                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($mmRelation['mm_table'])->createQueryBuilder();
                                $queryBuilder->getRestrictions()->removeAll();
                                $temp = $queryBuilder
                                    ->insert($mmRelation['mm_table'])
                                    ->values(
                                        $row
                                    )
                                    ->execute();
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
                $flexFormTools = new \TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools();
                $row[$key] = $flexFormTools->flexArray2Xml($row[$key], true);
            }
        }
        $translatedRow = $this->getTranslatedCompleteRow($tablename, $l10nParent, $targetLanguage);

        if (!empty($translatedRow)) {
            // Only Update row
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            if (!self::$onlyDebug) {
                $temp = $queryBuilder
                    ->update($tablename)
                    ->where(
                        $queryBuilder->expr()->eq('uid', $translatedRow['uid'])
                    );

                foreach ($row as $key => $value) {
                    $temp = $temp->set($key, $value);
                }

                $temp->execute();
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
                )
            ) {
                if (
                    !empty($GLOBALS['TCA'][$tablename]['columns'][$key]['config']['type'])
                    &&  (
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
                ->execute();
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
                            ->execute();
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
        $return[implode('.',$key)]['vDEF'] = $value;
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

                if (!empty($return[$parentTableName][$rowUid][$field]['data'])) { // malformwd flexform issue
                    foreach ($return[$parentTableName][$rowUid][$field]['data'] as $sheetName => $sheetData) {
                        if (in_array($keyName, array_keys($sheetData['lDEF']))) {
                            $tabName = $sheetName;
                            break;
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

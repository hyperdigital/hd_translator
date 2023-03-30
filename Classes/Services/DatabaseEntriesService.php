<?php
namespace Hyperdigital\HdTranslator\Services;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

class DatabaseEntriesService
{
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

        return $row;
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
    public function getCompleteInlinedRows(string $tablename, int $parentUid, string $foreignField = '', string $foreignSortby = '', string $foreignTableField = '', array $foreignMatchFields = [])
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tablename)->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $where = [];
        if (!empty($parentUid) && !empty($foreignField)) {
            $where[] = $queryBuilder->expr()->eq($foreignField, $parentUid);
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
    protected function getFieldKeyAndValue(string $tablename, string $field, $row, &$return, $specialFieldNameOutput = '')
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
                    break;
                case 'inline':
                    $this->getInlinedRowsFieldKeyAndValue($tablename, $field, $row, $return, $specialFieldNameOutput);
                    break;
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

        $rows = $this->getCompleteInlinedRows($foreginTable, $parentUid, $foreginField);

        if (!empty($rows)) {
            if (
                !empty($GLOBALS['TCA'][$foreginTable]['ctrl']['type']) // type field is defined
                && isset($row[$GLOBALS['TCA'][$foreginTable]['ctrl']['type']]) // row has this field
                && isset($GLOBALS['TCA'][$foreginTable]['types'][$row[$GLOBALS['TCA'][$foreginTable]['ctrl']['type']]]) // types has value for this field
            ) {
                $typeArray = $GLOBALS['TCA'][$foreginTable]['types'][$row[$GLOBALS['TCA'][$foreginTable]['ctrl']['type']]];
            } else {
                $typeArray = $GLOBALS['TCA'][$foreginTable]['types'];
                $typeArray = array_shift(array_slice($typeArray, 0, 1));
            }

            foreach ($rows as $rowInlined) {
                if (!empty($typeArray['translator_export'])) {
                    $listOfFields = GeneralUtility::trimExplode(',',$typeArray['translator_export']);
                }  else {
                    $listOfFields = $this->getListOfFieldsFromRow($foreginTable, $rowInlined);
                }

                foreach ($listOfFields as $field) {
                    $tempName = $specialFieldNameOutput.'.'.$rowInlined['uid'].'.'.$field;
                    $this->getFieldKeyAndValue($foreginTable, $field, $rowInlined, $return, $tempName);
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
        if (
            !empty($GLOBALS['TCA'][$tablename]['ctrl']['type']) // type field is defined
            && isset($row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]) // row has this field
            && isset($GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]]) // types has value for this field
        ) {
            $typeArray = $GLOBALS['TCA'][$tablename]['types'][$row[$GLOBALS['TCA'][$tablename]['ctrl']['type']]];
        } else {
            $typeArray = $GLOBALS['TCA'][$tablename]['types'];
            $typeArray = array_shift(array_slice($typeArray, 0, 1));
        }

        if (!empty($typeArray['translator_export'])) {
            $listOfFields = GeneralUtility::trimExplode(',',$typeArray['translator_export']);
        }  else {
            $listOfFields = $this->getListOfFieldsFromRow($tablename, $row);
        }

        foreach ($listOfFields as $field) {
            $this->getFieldKeyAndValue($tablename, $field, $row, $return);
        }

        return $return;
    }

    protected function prepareDataFromRow($uid, $row, $targetLanguage, $tablename, $translatedData = [])
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
            $return[$tablename.'.'.$fieldname] = [
                'default' => $value['value'],
                $targetLanguage => $translatedData[$fieldname] ?? $value['value'],
                '_label' => $value['label'],
                '_html' => $value['html'],
                '_notes' => $notes
            ];
        }

        return $return;
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
}

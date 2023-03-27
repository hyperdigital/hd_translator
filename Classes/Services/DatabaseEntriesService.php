<?php
namespace Hyperdigital\HdTranslator\Services;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Localization\Locales;

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
     * returns to $return key => value for the field by it's TCA settings
     *
     * @param string $tablename
     * @param string $field
     * @param $row
     * @param $return
     */
    protected function getFieldKeyAndValue(string $tablename, string $field, $row, &$return)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($tablename, $row);
        }

        if (!empty($GLOBALS['TCA'][$tablename]['columns'][$field]['config']['type'])) {
            // Switch by TCA type of the field
            switch ($GLOBALS['TCA'][$tablename]['columns'][$field]['config']['type']) {
                case 'input':
                case 'text':
                case 'slug':
                    $return[$field] = $row[$field] ?? '';
                    break;
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
            $row = $this->getCompleteRow($row, $targetLanguage, $tablename);
        }

        foreach ($row as $fieldname => $value) {
            $return[$tablename.'.'.$fieldname.'.'.$uid] = [
                'default' => $value,
                $targetLanguage => $translatedData[$fieldname] ?? $value
            ];
        }

        return $return;
    }

    /**
     * @param $uid - UID of the row (needed when $row is already ready for export)
     * @param $row
     * @param $targetLanguage
     * @param $tablename
     * @param bool $enableTranslatedData - if false, always the provided $row data are used
     */
    public function exportDatabaseRowToXlf($uid, $row, $targetLanguage, $tablename, $enableTranslatedData = true)
    {
        if (is_int($row)) {
            $row = $this->getCompleteRow($row, $targetLanguage, $tablename);
        }

        $test = GeneralUtility::makeInstance(Locales::class)->getIsoMapping();
        var_dump($test);
        die();

        $data = $this->prepareDataFromRow($uid, $row, $targetLanguage, $tablename);
        
        $xlfService = GeneralUtility::makeInstance(\Hyperdigital\HdTranslator\Services\XlfService::class);

        $output = $xlfService->dataToXlf($data, $targetLanguage);

        return $output;
    }
}
<?php
namespace Hyperdigital\HdTranslator\Services;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DatabaseEntriesService
{
    public function getLabel($tablename, $row)
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
     * @param int $rowUid
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
}
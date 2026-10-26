<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Counts how many people ask for a translation, per language.
 *
 * The number of translated strings says how much text a language has, not how many visitors ever
 * wanted it. Neither does DeepL, which counts requests, nor analytics, which cannot tell an
 * italian speaking visitor who used the translation from one who did not. That number is what
 * decides whether a language is worth maintaining as real pages.
 *
 * What is stored is one row per language, per day, per visitor, and the visitor is a hash that
 * cannot be traced back to anybody: it is keyed with the installation's encryption key *and the
 * day*, so once the day is over the same person hashes differently and the old hash matches
 * nothing. No address and no user agent is written anywhere.
 */
class AiRequestStatisticsService
{
    public const TABLE = 'tx_hdtranslator_ai_request';

    protected const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Windows the overview reports on, in days. 0 means everything that is still kept.
     */
    public const WINDOWS = ['today' => 1, 'week' => 7, 'month' => 30, 'total' => 0];

    public function isEnabled(): bool
    {
        try {
            $configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('hd_translator', 'aiRequestStatistics');
        } catch (\Throwable $e) {
            return true;
        }

        // not configured yet means on: the counting is the point of the feature and what it
        // writes is anonymous, switching it off is a deliberate choice
        return $configuration === null || $configuration === '' || (bool)$configuration;
    }

    public function getRetentionDays(): int
    {
        try {
            $days = (int)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('hd_translator', 'aiRequestStatisticsRetention');
        } catch (\Throwable $e) {
            $days = 0;
        }

        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Notes that somebody asked for this language today. Never throws: a translation must not
     * fail because a counter could not be written.
     */
    public function record(ServerRequestInterface $request, string $targetLanguage, int $strings): void
    {
        if (!$this->isEnabled() || $targetLanguage === '') {
            return;
        }

        try {
            $this->countVisit($this->visitorHash($request), $targetLanguage, max($strings, 0));
        } catch (\Throwable $e) {
            GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('Could not record the AI translation request: ' . $e->getMessage());
        }
    }

    protected function countVisit(string $visitor, string $targetLanguage, int $strings): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE);
        $identity = ['target_language' => $targetLanguage, 'day' => $this->today(), 'visitor' => $visitor];

        if ($this->increase($connection, $identity, $strings) > 0) {
            return;
        }

        try {
            $connection->insert(self::TABLE, $identity + [
                'requests' => 1,
                'strings' => $strings,
                'crdate' => $GLOBALS['EXEC_TIME'] ?? time(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // another request of the same visitor got there first
            $this->increase($connection, $identity, $strings);
        }
    }

    protected function increase(Connection $connection, array $identity, int $strings): int
    {
        $table = $connection->quoteIdentifier(self::TABLE);
        $where = [];
        $parameters = [];
        foreach ($identity as $column => $value) {
            $where[] = $connection->quoteIdentifier($column) . ' = ?';
            $parameters[] = $value;
        }

        return (int)$connection->executeStatement(
            'UPDATE ' . $table . ' SET '
            . $connection->quoteIdentifier('requests') . ' = ' . $connection->quoteIdentifier('requests') . ' + 1, '
            . $connection->quoteIdentifier('strings') . ' = ' . $connection->quoteIdentifier('strings') . ' + ? '
            . 'WHERE ' . implode(' AND ', $where),
            array_merge([$strings], $parameters)
        );
    }

    /**
     * Unique visitors and request counts per language, for every window of WINDOWS.
     *
     * @return array<string, array{today: int, week: int, month: int, total: int, requests: int, strings: int}>
     */
    public function getStatisticsByLanguage(): array
    {
        $return = [];

        foreach (self::WINDOWS as $window => $days) {
            foreach ($this->countVisitors($days) as $language => $visitors) {
                $return[$language][$window] = $visitors;
            }
        }

        foreach ($this->sumRequests(self::WINDOWS['month']) as $language => $sums) {
            $return[$language]['requests'] = $sums['requests'];
            $return[$language]['strings'] = $sums['strings'];
        }

        // a language nobody asked for still has to appear with zeroes
        foreach ($return as $language => $row) {
            $return[$language] = $row + array_fill_keys(array_keys(self::WINDOWS), 0) + ['requests' => 0, 'strings' => 0];
        }

        return $return;
    }

    /**
     * @return array<string, int> language => unique visitors
     */
    protected function countVisitors(int $days): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                'COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('visitor') . ') AS visitors'
            )
            ->from(self::TABLE)
            ->groupBy('target_language');

        if ($days > 0) {
            $queryBuilder->where(
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            );
        }

        $return = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $return[(string)$row['target_language']] = (int)$row['visitors'];
        }

        return $return;
    }

    /**
     * @return array<string, array{requests: int, strings: int}>
     */
    protected function sumRequests(int $days): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                'SUM(' . $queryBuilder->quoteIdentifier('requests') . ') AS requests',
                'SUM(' . $queryBuilder->quoteIdentifier('strings') . ') AS strings'
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            )
            ->groupBy('target_language')
            ->executeQuery()
            ->fetchAllAssociative();

        $return = [];
        foreach ($rows as $row) {
            $return[(string)$row['target_language']] = [
                'requests' => (int)$row['requests'],
                'strings' => (int)$row['strings'],
            ];
        }

        return $return;
    }

    /**
     * Drops what is older than the retention. There is no scheduler task for it, the overview
     * calls this when it is opened, which is the only place the numbers are looked at anyway.
     */
    public function prune(?int $retentionDays = null): int
    {
        $days = $retentionDays ?? $this->getRetentionDays();

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        return (int)$queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            )
            ->executeStatement();
    }

    /**
     * A visitor, as far as this counter is concerned.
     *
     * Keyed with the day, so the same person is a different hash tomorrow and yesterday's rows
     * can no longer be tied to anyone. The address itself is never stored.
     */
    protected function visitorHash(ServerRequestInterface $request): string
    {
        $address = $this->remoteAddress($request);
        $agent = (string)($request->getHeaderLine('User-Agent') ?: '');
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return hash_hmac('sha256', $address . '|' . $agent, $secret . '|' . $this->today());
    }

    /**
     * Taken from the request rather than from the environment, so a reverse proxy is resolved the
     * way the rest of TYPO3 resolves it. GeneralUtility::getIndpEnv() would do the same but is
     * deprecated since TYPO3 14.3, and it caches, which hides a changing address.
     */
    protected function remoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }

        return (string)$normalizedParams->getRemoteAddress();
    }

    protected function today(): int
    {
        return (int)date('Ymd', $GLOBALS['EXEC_TIME'] ?? time());
    }

    /**
     * The oldest day still inside a window of $days, as a Ymd integer.
     */
    protected function firstDayOf(int $days): int
    {
        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $first = strtotime('-' . max($days - 1, 0) . ' days', $now);

        return (int)date('Ymd', $first !== false ? $first : $now);
    }
}

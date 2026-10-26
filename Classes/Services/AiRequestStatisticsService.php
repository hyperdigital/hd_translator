<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
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
 * One row is one visitor asking for one language on one day, and nothing else: no counters, no
 * timestamps. The row *is* its primary key, so InnoDB stores it once instead of storing a row and
 * a separate index pointing at it.
 *
 * The visitor is a hash that cannot be traced back to anybody: it is keyed with the installation's
 * encryption key *and the day*, so once the day is over the same person hashes differently and the
 * old hash matches nothing. No address and no user agent is written anywhere.
 */
class AiRequestStatisticsService
{
    public const TABLE = 'tx_hdtranslator_ai_request';

    protected const DEFAULT_RETENTION_DAYS = 365;

    /**
     * Sixteen hex characters are 64 bits. Two visitors of the same language on the same day
     * colliding is of the order of 10^-11 even at twenty thousand a day, and the whole point of
     * the value is to be forgotten tomorrow.
     */
    protected const HASH_LENGTH = 16;

    /**
     * Windows the screens report on, in days. 0 means everything that is still kept.
     */
    public const WINDOWS = ['today' => 1, 'week' => 7, 'month' => 30, 'total' => 0];

    /**
     * Marks that the old rows were already dropped today, so it happens once a day rather than
     * on every request or only when somebody opens the screen.
     */
    protected const PRUNE_MARKER = 'hdtranslator-pruned';

    public function isEnabled(): bool
    {
        try {
            $configuration = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('hd_translator', 'aiRequestStatistics');
        } catch (\Throwable $e) {
            return true;
        }

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
    public function record(ServerRequestInterface $request, string $targetLanguage): void
    {
        if (!$this->isEnabled() || $targetLanguage === '') {
            return;
        }

        try {
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable(self::TABLE)
                ->insert(self::TABLE, [
                    'target_language' => $targetLanguage,
                    'day' => $this->today(),
                    'visitor' => $this->visitorHash($request),
                ]);
        } catch (\Throwable $e) {
            // The same visitor asking again the same day is the normal case and lands on the
            // primary key; anything else is a counter that could not be written, which is not
            // worth failing a translation over either.
        }

        $this->pruneOncePerDay();
    }

    /**
     * Unique visitors per language, for every window of WINDOWS, plus the window before the last
     * thirty days so the screen can say whether a language is gaining or losing interest.
     *
     * @return array<string, array{today: int, week: int, month: int, total: int, previous: int, trend: int|null}>
     */
    public function getStatisticsByLanguage(): array
    {
        $return = [];

        foreach (self::WINDOWS as $window => $days) {
            foreach ($this->countVisitors($this->firstDayOf($days), $days > 0 ? null : null) as $language => $visitors) {
                $return[$language][$window] = $visitors;
            }
        }

        // the thirty days before the last thirty, for the trend
        foreach ($this->countVisitors($this->firstDayOf(60), $this->firstDayOf(31)) as $language => $visitors) {
            $return[$language]['previous'] = $visitors;
        }

        foreach ($return as $language => $row) {
            $row += array_fill_keys(array_keys(self::WINDOWS), 0) + ['previous' => 0];

            // a language nobody asked for before cannot have a percentage, it is simply new
            $row['trend'] = $row['previous'] > 0
                ? (int)round(($row['month'] - $row['previous']) / $row['previous'] * 100)
                : null;

            $return[$language] = $row;
        }

        return $return;
    }

    /**
     * Unique visitors per language and day, for a chart.
     *
     * One query; days nobody asked on are filled in as zero here rather than leaving gaps the
     * chart would have to guess at.
     *
     * @return array{days: array<int, string>, series: array<string, array<int, int>>}
     */
    public function getDailySeries(int $days): array
    {
        $days = max(min($days, 365), 2);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                $queryBuilder->quoteIdentifier('day'),
                'COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('visitor') . ') AS visitors'
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            )
            ->groupBy('target_language', 'day')
            ->executeQuery()
            ->fetchAllAssociative();

        $counted = [];
        foreach ($rows as $row) {
            $counted[(string)$row['target_language']][(int)$row['day']] = (int)$row['visitors'];
        }

        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $calendar = [];
        for ($back = $days - 1; $back >= 0; $back--) {
            $timestamp = strtotime('-' . $back . ' days', $now);
            $calendar[] = (int)date('Ymd', $timestamp !== false ? $timestamp : $now);
        }

        $series = [];
        foreach ($counted as $language => $perDay) {
            $series[$language] = [];
            foreach ($calendar as $day) {
                $series[$language][] = $perDay[$day] ?? 0;
            }
        }

        return [
            'days' => array_map(
                static fn(int $day): string => substr((string)$day, 0, 4) . '-' . substr((string)$day, 4, 2) . '-' . substr((string)$day, 6, 2),
                $calendar
            ),
            'series' => $series,
        ];
    }

    /**
     * @param int $from first day of the window, as a Ymd integer
     * @param int|null $to last day of the window, null for "up to today"
     * @return array<string, int> language => unique visitors
     */
    protected function countVisitors(int $from, ?int $to = null): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                'COUNT(DISTINCT ' . $queryBuilder->quoteIdentifier('visitor') . ') AS visitors'
            )
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($from, Connection::PARAM_INT)))
            ->groupBy('target_language');

        if ($to !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte('day', $queryBuilder->createNamedParameter($to, Connection::PARAM_INT))
            );
        }

        $return = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $return[(string)$row['target_language']] = (int)$row['visitors'];
        }

        return $return;
    }

    /**
     * Drops what is older than the retention, at most once a day.
     *
     * It used to happen only when somebody opened the overview, so on an installation where
     * nobody ever does the table grew without end.
     */
    public function pruneOncePerDay(): void
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache(RequestThrottleService::CACHE);
            if ((int)($cache->get(self::PRUNE_MARKER) ?: 0) === $this->today()) {
                return;
            }

            $this->prune();
            $cache->set(self::PRUNE_MARKER, $this->today(), [], 172800);
        } catch (\Throwable $e) {
            // a cache that cannot be reached only means the next request tries again
        }
    }

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
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return substr(
            hash_hmac(
                'sha256',
                $this->remoteAddress($request) . '|' . $request->getHeaderLine('User-Agent'),
                $secret . '|' . $this->today()
            ),
            0,
            self::HASH_LENGTH
        );
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
     * The oldest day still inside a window of $days, as a Ymd integer. 0 days means everything.
     */
    protected function firstDayOf(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $now = $GLOBALS['EXEC_TIME'] ?? time();
        $first = strtotime('-' . ($days - 1) . ' days', $now);

        return (int)date('Ymd', $first !== false ? $first : $now);
    }
}

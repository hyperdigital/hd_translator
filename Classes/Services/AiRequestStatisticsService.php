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

    /**
     * Months survive their days. One row per language per month, written when the daily rows
     * of that month fall out of retention.
     */
    public const TABLE_MONTH = 'tx_hdtranslator_ai_request_month';

    /**
     * Which page a language was asked for on, bounded on both ends: a language-day accepts at
     * most PAGE_SLOTS distinct pages, and once the day has closed the prune keeps PAGE_KEEP of
     * them. Pages are also dropped sooner than the counting rows - a page that was busy a year
     * ago answers nothing anybody asks today.
     */
    public const TABLE_PAGE = 'tx_hdtranslator_ai_request_page';
    protected const PAGE_SLOTS = 50;
    protected const PAGE_KEEP = 10;
    protected const PAGE_RETENTION_DAYS = 90;

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
        $firstDayKept = $this->firstDayOf($days);

        // the month keeps what the days are about to lose, so a year of detail turning into
        // twelve rows is the only thing that happens at the retention boundary
        $this->rollUpBefore($firstDayKept);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        $dropped = (int)$queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt('day', $queryBuilder->createNamedParameter($firstDayKept, Connection::PARAM_INT))
            )
            ->executeStatement();

        $this->prunePages($days);

        return $dropped;
    }

    /**
     * Pages are dropped sooner than the counting rows, and every day that has closed is cut
     * down to the busiest PAGE_KEEP of its pages.
     */
    protected function prunePages(int $retentionDays): void
    {
        $keepFrom = $this->firstDayOf(min($retentionDays, self::PAGE_RETENTION_DAYS));

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE_PAGE);
        $queryBuilder
            ->delete(self::TABLE_PAGE)
            ->where(
                $queryBuilder->expr()->lt('day', $queryBuilder->createNamedParameter($keepFrom, Connection::PARAM_INT))
            )
            ->executeStatement();

        $closed = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE_PAGE);
        $days = $closed
            ->select('target_language', 'day')
            ->from(self::TABLE_PAGE)
            ->where(
                $closed->expr()->lt('day', $closed->createNamedParameter($this->today(), Connection::PARAM_INT))
            )
            ->groupBy('target_language', 'day')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($days as $row) {
            $this->trimPagesOfDay((string)$row['target_language'], (int)$row['day'], self::PAGE_KEEP);
        }
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
     * Which page the translation was asked for on, taken from the referer the browser sends
     * with the endpoint call - no change to the frontend and none to a project using it.
     *
     * This counts requests, not people: a page is not a visitor and pairing the two would
     * multiply the rows by the size of the site. See TABLE_PAGE for how it stays bounded.
     */
    public function recordPage(ServerRequestInterface $request, string $targetLanguage): void
    {
        if (!$this->isEnabled() || $targetLanguage === '') {
            return;
        }

        $page = $this->pageOf($request);
        if ($page === '') {
            return;
        }

        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE_PAGE);
            $day = $this->today();

            // update first: a page that was already seen today is the common case and costs
            // one statement, a page seen for the first time costs two
            $updated = $connection->executeStatement(
                'UPDATE ' . $connection->quoteIdentifier(self::TABLE_PAGE)
                    . ' SET ' . $connection->quoteIdentifier('requests') . ' = ' . $connection->quoteIdentifier('requests') . ' + 1'
                    . ' WHERE ' . $connection->quoteIdentifier('target_language') . ' = ?'
                    . ' AND ' . $connection->quoteIdentifier('day') . ' = ?'
                    . ' AND ' . $connection->quoteIdentifier('page') . ' = ?',
                [$targetLanguage, $day, $page]
            );

            if ($updated > 0) {
                return;
            }

            $connection->insert(self::TABLE_PAGE, [
                'target_language' => $targetLanguage,
                'day' => $day,
                'page' => $page,
                'requests' => 1,
            ]);

            // only a page nobody had seen today can push the day over its cap, so the trim
            // runs on that path alone
            $this->trimPagesOfDay($targetLanguage, $day, self::PAGE_SLOTS);
        } catch (\Throwable $e) {
            // a page that could not be counted is not worth failing a translation over
        }
    }

    /**
     * Requests per page for a language, busiest first.
     *
     * @return array<string, array<int, array{page: string, requests: int}>> language => pages
     */
    public function getTopPages(int $days = 30, int $limit = 10): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE_PAGE);

        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                $queryBuilder->quoteIdentifier('page'),
                'SUM(' . $queryBuilder->quoteIdentifier('requests') . ') AS requests'
            )
            ->from(self::TABLE_PAGE)
            ->where(
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            )
            ->groupBy('target_language', 'page')
            ->orderBy('requests', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $return = [];
        foreach ($rows as $row) {
            $language = (string)$row['target_language'];
            if (count($return[$language] ?? []) >= $limit) {
                continue;
            }
            $return[$language][] = ['page' => (string)$row['page'], 'requests' => (int)$row['requests']];
        }

        return $return;
    }

    /**
     * How the demand sits across the week. Read from the day column rather than asked of the
     * database, so it does not depend on which date functions the platform has.
     *
     * @return array<int, array{weekday: int, visitors: int, share: int}> monday first, share
     *         being the height of the busiest day in percent so a bar needs no arithmetic
     */
    public function getWeekdayProfile(int $days = 90): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('day'),
                'COUNT(*) AS visitors'
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->gte('day', $queryBuilder->createNamedParameter($this->firstDayOf($days), Connection::PARAM_INT))
            )
            ->groupBy('day')
            ->executeQuery()
            ->fetchAllAssociative();

        $profile = array_fill(1, 7, 0);
        foreach ($rows as $row) {
            $date = \DateTimeImmutable::createFromFormat('Ymd', (string)$row['day']);
            if ($date === false) {
                continue;
            }
            $profile[(int)$date->format('N')] += (int)$row['visitors'];
        }

        $busiest = max($profile);

        $return = [];
        foreach ($profile as $weekday => $visitors) {
            $return[] = [
                'weekday' => $weekday,
                'visitors' => $visitors,
                'share' => $busiest > 0 ? (int)round($visitors / $busiest * 100) : 0,
            ];
        }

        return $return;
    }

    /**
     * Whether the demand sits on a few languages or spreads across many.
     *
     * The share of the busiest three answers "would building one or two mutations serve most
     * of it". The index is the sum of the squared shares: 1 is everybody asking for the same
     * language, 1/n is every language asked for equally often.
     *
     * @param array<string, array<string, int|null>> $statistics as getStatisticsByLanguage() returns
     * @return array{languages: int, total: int, topShare: int, index: float, leaders: array<int, string>}
     */
    public function getConcentration(array $statistics, string $window = 'month'): array
    {
        $totals = [];
        foreach ($statistics as $language => $row) {
            $value = (int)($row[$window] ?? 0);
            if ($value > 0) {
                $totals[(string)$language] = $value;
            }
        }

        $sum = array_sum($totals);
        if ($sum === 0) {
            return ['languages' => 0, 'total' => 0, 'topShare' => 0, 'index' => 0.0, 'leaders' => []];
        }

        arsort($totals);
        $leaders = array_slice(array_keys($totals), 0, 3);

        $top = 0;
        foreach ($leaders as $language) {
            $top += $totals[$language];
        }

        $index = 0.0;
        foreach ($totals as $value) {
            $index += ($value / $sum) ** 2;
        }

        return [
            'languages' => count($totals),
            'total' => $sum,
            'topShare' => (int)round($top / $sum * 100),
            'index' => round($index, 3),
            'leaders' => $leaders,
        ];
    }

    /**
     * What each language has been translated of, in strings and in characters.
     *
     * Characters are what DeepL charges by, so this is the closest thing to the bill that the
     * installation itself can answer - without storing a number anywhere, because the source
     * texts are already in the cache.
     *
     * @return array<string, array{strings: int, characters: int}>
     */
    public function getVolumeByLanguage(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_hdtranslator_ai_translation');

        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                'COUNT(*) AS strings',
                'SUM(CHAR_LENGTH(' . $queryBuilder->quoteIdentifier('original_source') . ')) AS characters'
            )
            ->from('tx_hdtranslator_ai_translation')
            ->groupBy('target_language')
            ->executeQuery()
            ->fetchAllAssociative();

        $return = [];
        foreach ($rows as $row) {
            $return[(string)$row['target_language']] = [
                'strings' => (int)$row['strings'],
                'characters' => (int)$row['characters'],
            ];
        }

        return $return;
    }

    /**
     * Visitor-days per language and month, the archived months first joined with the ones whose
     * daily rows are still here, so the history does not jump at the retention boundary.
     *
     * @return array{months: array<int, int>, series: array<string, array<int, int>>}
     */
    public function getMonthlyHistory(int $months = 24): array
    {
        $months = max(min($months, 120), 1);

        $totals = [];

        $archived = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable(self::TABLE_MONTH)
            ->select('target_language', 'month', 'visitor_days')
            ->from(self::TABLE_MONTH)
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($archived as $row) {
            $totals[(string)$row['target_language']][(int)$row['month']] = (int)$row['visitor_days'];
        }

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $live = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                $queryBuilder->quoteIdentifier('day'),
                'COUNT(*) AS visitor_days'
            )
            ->from(self::TABLE)
            ->groupBy('target_language', 'day')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($live as $row) {
            $month = (int)substr((string)$row['day'], 0, 6);
            $language = (string)$row['target_language'];
            $totals[$language][$month] = ($totals[$language][$month] ?? 0) + (int)$row['visitor_days'];
        }

        $calendar = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01', $GLOBALS['EXEC_TIME'] ?? time()));
        for ($i = $months - 1; $i >= 0; $i--) {
            $calendar[] = (int)$cursor->modify('-' . $i . ' months')->format('Ym');
        }

        $series = [];
        foreach ($totals as $language => $byMonth) {
            $row = [];
            $seen = false;
            foreach ($calendar as $month) {
                $value = (int)($byMonth[$month] ?? 0);
                $seen = $seen || $value > 0;
                $row[] = $value;
            }
            if ($seen) {
                $series[$language] = $row;
            }
        }

        ksort($series);

        return ['months' => $calendar, 'series' => $series];
    }

    /**
     * Folds the days that are about to be dropped into their month.
     *
     * Each row of TABLE is one language, one day, one visitor, so counting them per month is
     * exactly the visitor-days of that month. Adding rather than replacing keeps it correct
     * when the retention boundary falls inside a month and the rest arrives a prune later;
     * running twice adds nothing, because the rows are gone by then.
     */
    protected function rollUpBefore(int $firstDayKept): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);

        $rows = $queryBuilder
            ->selectLiteral(
                $queryBuilder->quoteIdentifier('target_language'),
                $queryBuilder->quoteIdentifier('day'),
                'COUNT(*) AS visitor_days'
            )
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt('day', $queryBuilder->createNamedParameter($firstDayKept, Connection::PARAM_INT))
            )
            ->groupBy('target_language', 'day')
            ->executeQuery()
            ->fetchAllAssociative();

        $byMonth = [];
        foreach ($rows as $row) {
            $month = (int)substr((string)$row['day'], 0, 6);
            $byMonth[(string)$row['target_language']][$month] = ($byMonth[(string)$row['target_language']][$month] ?? 0)
                + (int)$row['visitor_days'];
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable(self::TABLE_MONTH);
        foreach ($byMonth as $language => $months) {
            foreach ($months as $month => $visitorDays) {
                $updated = $connection->executeStatement(
                    'UPDATE ' . $connection->quoteIdentifier(self::TABLE_MONTH)
                        . ' SET ' . $connection->quoteIdentifier('visitor_days') . ' = ' . $connection->quoteIdentifier('visitor_days') . ' + ?'
                        . ' WHERE ' . $connection->quoteIdentifier('target_language') . ' = ?'
                        . ' AND ' . $connection->quoteIdentifier('month') . ' = ?',
                    [$visitorDays, $language, $month]
                );

                if ($updated === 0) {
                    $connection->insert(self::TABLE_MONTH, [
                        'target_language' => $language,
                        'month' => $month,
                        'visitor_days' => $visitorDays,
                    ]);
                }
            }
        }
    }

    /**
     * Keeps the busiest $keep pages of one language-day and drops the rest.
     */
    protected function trimPagesOfDay(string $targetLanguage, int $day, int $keep): void
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE_PAGE);

        $pages = $queryBuilder
            ->select('page')
            ->from(self::TABLE_PAGE)
            ->where(
                $queryBuilder->expr()->eq('target_language', $queryBuilder->createNamedParameter($targetLanguage)),
                $queryBuilder->expr()->eq('day', $queryBuilder->createNamedParameter($day, Connection::PARAM_INT))
            )
            ->orderBy('requests', 'DESC')
            ->addOrderBy('page', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $drop = array_slice($pages, $keep);
        if ($drop === []) {
            return;
        }

        $delete = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE_PAGE);
        $delete
            ->delete(self::TABLE_PAGE)
            ->where(
                $delete->expr()->eq('target_language', $delete->createNamedParameter($targetLanguage)),
                $delete->expr()->eq('day', $delete->createNamedParameter($day, Connection::PARAM_INT)),
                $delete->expr()->in('page', $delete->createNamedParameter($drop, Connection::PARAM_STR_ARRAY))
            )
            ->executeStatement();
    }

    /**
     * The page a request came from, as a path. The referer is a full url and carries whatever
     * query the page had; only the path is kept, so nothing a visitor typed into a search or a
     * form ends up in the table.
     */
    protected function pageOf(ServerRequestInterface $request): string
    {
        $referer = trim($request->getHeaderLine('Referer'));
        if ($referer === '') {
            return '';
        }

        $path = parse_url($referer, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        return substr($path, 0, 191);
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

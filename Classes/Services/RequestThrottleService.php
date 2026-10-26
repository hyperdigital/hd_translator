<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Services;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Keeps one visitor from calling the translation endpoint as often as they like.
 *
 * That endpoint is reachable without a login, because the frontend has to reach it, and every
 * string it has not seen before is paid for at DeepL. The size of a single request was bounded,
 * the number of requests was not, so anybody who found the URL could spend the site's quota and
 * fill the request statistics with invented visitors.
 *
 * The counter lives in a cache entry per visitor and minute. Losing it is harmless, which is why
 * a cache is the right place: the worst a flushed cache does is grant somebody another minute.
 */
class RequestThrottleService
{
    public const CACHE = 'hd_translator_throttle';

    protected const DEFAULT_REQUESTS_PER_MINUTE = 60;

    /**
     * @return int requests one visitor may send per minute, 0 when the throttle is off
     */
    public function getLimit(): int
    {
        try {
            $configured = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('hd_translator', 'requestsPerMinute');
        } catch (\Throwable $e) {
            return self::DEFAULT_REQUESTS_PER_MINUTE;
        }

        if ($configured === null || $configured === '') {
            return self::DEFAULT_REQUESTS_PER_MINUTE;
        }

        return max((int)$configured, 0);
    }

    /**
     * Counts this request and says whether the visitor has gone over the limit.
     *
     * Never throws: a cache that cannot be reached must not stop a translation, it only means
     * this request goes uncounted.
     */
    public function isExceeded(ServerRequestInterface $request): bool
    {
        $limit = $this->getLimit();
        if ($limit <= 0) {
            return false;
        }

        try {
            $cache = $this->getCache();
            $identifier = $this->identifier($request);

            $used = (int)($cache->get($identifier) ?: 0);
            if ($used >= $limit) {
                return true;
            }

            // two minutes, so the entry of the minute that is still being counted cannot expire
            // underneath it
            $cache->set($identifier, $used + 1, [], 120);
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Seconds the visitor should wait, for the Retry-After header.
     */
    public function getRetryAfter(): int
    {
        return 60 - ((int)date('s', $GLOBALS['EXEC_TIME'] ?? time()));
    }

    protected function getCache(): FrontendInterface
    {
        return GeneralUtility::makeInstance(CacheManager::class)->getCache(self::CACHE);
    }

    /**
     * One visitor, one minute. Built from the address only: the user agent is what an attacker
     * varies most easily, and counting it would hand out a fresh budget with every change.
     */
    protected function identifier(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof \TYPO3\CMS\Core\Http\NormalizedParams) {
            $normalizedParams = \TYPO3\CMS\Core\Http\NormalizedParams::createFromRequest($request);
        }

        return hash(
            'sha256',
            $normalizedParams->getRemoteAddress() . '|' . date('YmdHi', $GLOBALS['EXEC_TIME'] ?? time())
        );
    }
}

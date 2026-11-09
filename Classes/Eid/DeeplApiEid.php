<?php
namespace Hyperdigital\HdTranslator\Eid;

use Hyperdigital\HdTranslator\Services\AiRequestStatisticsService;
use Hyperdigital\HdTranslator\Services\DeeplApiService;
use Hyperdigital\HdTranslator\Services\RequestThrottleService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class DeeplApiEid
{
    /**
     * Maximum amount of strings accepted by a single translate request.
     *
     * This is not DeepL's limit of 50, the service splits what it has to forward. A page that
     * is already cached is resolved in one request instead of one per fifty strings, and what a
     * single request may cost is still bound by MAX_TOTAL_LENGTH, which did not change.
     */
    protected const MAX_TEXTS_PER_REQUEST = 500;

    /**
     * Maximum length of a single string (in characters)
     */
    protected const MAX_TEXT_LENGTH = 5000;

    /**
     * Maximum accumulated length of all strings of one request (in characters)
     */
    protected const MAX_TOTAL_LENGTH = 50000;

    /**
     * A translation that was served from the browser cache. Not a banner event: it is counted
     * into the request statistics, where an actual translate call would have landed.
     */
    protected const EVENT_USED = 'used';

    public function fetchSupportedLanguages(ServerRequestInterface $request): ResponseInterface
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);

        if (!$deeplApiService->isEnabled()) {
            return new JsonResponse(['error' => 'DeepL translations are not configured'], 503);
        }

        return new JsonResponse($deeplApiService->getAvailableLanguages(true));
    }

    /**
     * Reports that the visitor was offered a translation, that they agreed to use it, or that
     * they used one the browser already had.
     *
     * The banner lives in the site package, not in this extension, so it cannot be seen from
     * here - it says so itself. Without that, "shown" and "accepted" are the same row: a banner
     * that translates its own text already counts as a request, and the translate endpoint
     * cannot tell the two apart afterwards.
     *
     * EVENT_USED exists for the other direction. A returning visitor whose page is translated
     * entirely out of the browser cache never reaches the translate endpoint, so the demand
     * counter missed exactly the people who use the translation most. This closes that: it
     * writes the same row the translate endpoint would have written.
     */
    public function banner(ServerRequestInterface $request): ResponseInterface
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);

        if (!$deeplApiService->isEnabled()) {
            return new JsonResponse(['error' => 'DeepL translations are not configured'], 503);
        }

        // public endpoint, same as the other two: without a limit anybody who finds the url
        // could fill the statistics with invented visitors
        $throttle = GeneralUtility::makeInstance(RequestThrottleService::class);
        if ($throttle->isExceeded($request)) {
            return (new JsonResponse(['error' => 'Too many requests'], 429))
                ->withHeader('Retry-After', (string)$throttle->getRetryAfter());
        }

        $input = json_decode((string)$request->getBody(), true);

        if (!is_array($input) || !isset($input['targetLang']) || !isset($input['event'])) {
            return new JsonResponse(['error' => 'Missing targetLang or event'], 400);
        }

        // only synchronized languages, normalized the same way the translate endpoint does it,
        // so the statistics of one language cannot be split over two spellings
        $targetLanguage = strtoupper(trim((string)$input['targetLang']));
        if (!$deeplApiService->isSupportedLanguage($targetLanguage)) {
            return new JsonResponse(['error' => 'Unsupported targetLang'], 400);
        }

        $event = strtolower(trim((string)$input['event']));
        $statistics = GeneralUtility::makeInstance(AiRequestStatisticsService::class);

        if ($event === self::EVENT_USED) {
            $statistics->record($request, $targetLanguage);
            $statistics->recordPage($request, $targetLanguage);
        } elseif (in_array($event, AiRequestStatisticsService::BANNER_EVENTS, true)) {
            $statistics->recordBannerEvent($request, $targetLanguage, $event);
        } else {
            return new JsonResponse(['error' => 'Unknown event'], 400);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);

        if (!$deeplApiService->isEnabled()) {
            return new JsonResponse(['error' => 'DeepL translations are not configured'], 503);
        }

        // The endpoint has no login, because the frontend has to reach it, and every string it
        // has not seen is paid for at DeepL. Without this anybody who finds the url can spend
        // the quota and fill the request statistics with invented visitors.
        $throttle = GeneralUtility::makeInstance(RequestThrottleService::class);
        if ($throttle->isExceeded($request)) {
            return (new JsonResponse(['error' => 'Too many requests'], 429))
                ->withHeader('Retry-After', (string)$throttle->getRetryAfter());
        }

        $input = json_decode((string)$request->getBody(), true);

        if (!is_array($input) || !isset($input['text']) || !isset($input['targetLang'])) {
            return new JsonResponse(['error' => 'Missing text or targetLang'], 400);
        }

        $texts = is_array($input['text']) ? array_values($input['text']) : [$input['text']];

        // Only languages synchronized from DeepL are accepted, so the endpoint cannot be
        // used to request arbitrary target languages. The code is normalized so the local
        // translation cache does not get fragmented by differently cased clients.
        $targetLanguage = strtoupper(trim((string)$input['targetLang']));
        if (!$deeplApiService->isSupportedLanguage($targetLanguage)) {
            return new JsonResponse(['error' => 'Unsupported targetLang'], 400);
        }

        if (count($texts) > self::MAX_TEXTS_PER_REQUEST) {
            return new JsonResponse([
                'error' => 'Too many texts, maximum is ' . self::MAX_TEXTS_PER_REQUEST,
            ], 413);
        }

        $totalLength = 0;
        foreach ($texts as $text) {
            if (!is_string($text)) {
                return new JsonResponse(['error' => 'Every text has to be a string'], 400);
            }

            $length = mb_strlen($text);
            if ($length > self::MAX_TEXT_LENGTH) {
                return new JsonResponse([
                    'error' => 'Text too long, maximum is ' . self::MAX_TEXT_LENGTH . ' characters',
                ], 413);
            }
            $totalLength += $length;
        }

        if ($totalLength > self::MAX_TOTAL_LENGTH) {
            return new JsonResponse([
                'error' => 'Payload too long, maximum is ' . self::MAX_TOTAL_LENGTH . ' characters',
            ], 413);
        }

        // Counted here rather than in the service: this is the entry point a frontend goes
        // through, and it stays counted even where a project replaces the service with its own.
        $statistics = GeneralUtility::makeInstance(AiRequestStatisticsService::class);
        $statistics->record($request, $targetLanguage);
        $statistics->recordPage($request, $targetLanguage);

        try {
            $translations = $deeplApiService->translateTexts($texts, $targetLanguage);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Failed to contact DeepL'], 502);
        }

        return new JsonResponse(['translations' => $translations]);
    }
}

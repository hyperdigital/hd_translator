<?php
namespace Hyperdigital\HdTranslator\Eid;

use Hyperdigital\HdTranslator\Services\DeeplApiService;
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

    public function fetchSupportedLanguages(ServerRequestInterface $request): ResponseInterface
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);

        if (!$deeplApiService->isEnabled()) {
            return new JsonResponse(['error' => 'DeepL translations are not configured'], 503);
        }

        return new JsonResponse($deeplApiService->getAvailableLanguages(true));
    }

    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);

        if (!$deeplApiService->isEnabled()) {
            return new JsonResponse(['error' => 'DeepL translations are not configured'], 503);
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

        try {
            $translations = $deeplApiService->translateTexts($texts, $targetLanguage);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Failed to contact DeepL'], 502);
        }

        return new JsonResponse(['translations' => $translations]);
    }
}

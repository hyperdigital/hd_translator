<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Reaction;

use Hyperdigital\HdTranslator\Helpers\TranslationHelper;
use Hyperdigital\HdTranslator\Services\DatabaseEntriesService;
use Hyperdigital\HdTranslator\Services\TranslationFormatService;
use Hyperdigital\HdTranslator\Services\TranslationQaService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reactions\Model\ReactionInstruction;
use TYPO3\CMS\Reactions\Reaction\ReactionInterface;

/**
 * Takes a finished translation over HTTP, so a translation system can push its result back
 * instead of somebody downloading a file and uploading it into the module by hand.
 *
 * The endpoint, its secret and its identifier come from the Reactions module of TYPO3, which also
 * decides which backend user the write runs as. The reaction record adds what this extension
 * needs: the language to write into, optionally the site the import is confined to, and what to
 * do with entries that fail the quality check.
 *
 * Payload:
 *   {
 *     "language": 2,                  optional, overrides the language of the reaction record
 *     "format": "xlf",                optional file extension, defaults to xlf
 *     "content": "<xliff …>",         the file, either plain
 *     "contentBase64": "PHhsaWZm…"    or base64 encoded
 *   }
 */
class ImportTranslationReaction implements ReactionInterface
{
    public const QA_REPORT = 'report';
    public const QA_SKIP = 'skip';
    public const QA_REJECT = 'reject';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly TranslationFormatService $translationFormatService,
        protected readonly TranslationQaService $translationQaService,
    ) {}

    public static function getType(): string
    {
        return 'hd-translator-import';
    }

    public static function getDescription(): string
    {
        return 'LLL:EXT:hd_translator/Resources/Private/Language/locallang_db.xlf:sys_reaction.reaction_type.hd_translator_import';
    }

    public static function getIconIdentifier(): string
    {
        return 'actions-localize';
    }

    public function react(ServerRequestInterface $request, array $payload, ReactionInstruction $reaction): ResponseInterface
    {
        $settings = $reaction->toArray();

        $content = $this->resolveContent($payload);
        if ($content === null) {
            return $this->jsonResponse(['success' => false, 'error' => 'No content given, expected "content" or "contentBase64".'], 400);
        }

        $extension = strtolower((string)($payload['format'] ?? 'xlf'));
        if (!$this->translationFormatService->canLoad($extension)) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Unsupported format "' . $extension . '".',
                'supported' => $this->translationFormatService->getImportExtensions(),
            ], 400);
        }

        $language = (int)($payload['language'] ?? $settings['hdtranslator_language'] ?? 0);
        if ($language <= 0) {
            return $this->jsonResponse(['success' => false, 'error' => 'No target language given.'], 400);
        }

        $entries = $this->translationFormatService->load($content, $extension);
        if ($entries === []) {
            return $this->jsonResponse(['success' => false, 'error' => 'The file contains no translations.'], 400);
        }

        $qa = $this->translationQaService->check($entries);
        $qaMode = (string)($settings['hdtranslator_qa_mode'] ?? self::QA_REPORT);

        if ($qaMode === self::QA_REJECT && $qa['errors'] > 0) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'The file did not pass the quality check.',
                'qa' => $this->summariseQa($qa),
            ], 422);
        }

        $data = $this->translationFormatService->toImportData($entries);

        if ($qaMode === self::QA_SKIP) {
            foreach ($qa['failedKeys'] as $failedKey) {
                unset($data[$failedKey]);
            }
        }

        $outOfScope = $this->removeKeysOutsideSite($data, (string)($settings['hdtranslator_site'] ?? ''));

        if ($data === []) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Nothing was left to import.',
                'outOfScope' => $outOfScope,
                'qa' => $this->summariseQa($qa),
            ], 422);
        }

        $databaseEntriesService = GeneralUtility::makeInstance(DatabaseEntriesService::class);
        $databaseEntriesService->importIntoDatabase($data, $language);
        $stats = $databaseEntriesService->getImportStats();

        $touched = $stats['inserts'] + $stats['updates'] + $stats['fails'];
        if ($touched === 0) {
            // keys that match no record are the usual sign of a file built by hand, saying
            // "success" with nothing written is what makes that cost an afternoon to find
            return $this->jsonResponse([
                'success' => false,
                'error' => 'None of the keys matched a record. Keys look like "table.uid.field".',
                'keys' => array_slice(array_keys($data), 0, 10),
                'qa' => $this->summariseQa($qa),
            ], 422);
        }

        return $this->jsonResponse([
            'success' => $stats['fails'] === 0,
            'language' => $language,
            'inserted' => $stats['inserts'],
            'updated' => $stats['updates'],
            'failed' => $stats['fails'],
            'failMessages' => $stats['failsMessages'],
            'outOfScope' => $outOfScope,
            'qa' => $this->summariseQa($qa),
        ], $stats['fails'] === 0 ? 200 : 207);
    }

    /**
     * The file may arrive as plain text or base64, because not every translation system can put
     * raw XML into a JSON string.
     */
    protected function resolveContent(array $payload): ?string
    {
        if (!empty($payload['contentBase64']) && is_string($payload['contentBase64'])) {
            $decoded = base64_decode($payload['contentBase64'], true);

            return $decoded === false ? null : $decoded;
        }

        if (!empty($payload['content']) && is_string($payload['content'])) {
            return $payload['content'];
        }

        return null;
    }

    /**
     * Drops the entries whose record does not live in the page tree of the configured site.
     *
     * This is what makes one endpoint per site possible: a token handed to the agency of one
     * site cannot write the content of another, even though the keys of both look alike.
     *
     * @param array<string, string> $data modified in place
     * @return array<int, string> the keys that were dropped
     */
    protected function removeKeysOutsideSite(array &$data, string $siteIdentifier): array
    {
        if ($siteIdentifier === '') {
            return [];
        }

        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable $e) {
            // a reaction pointing at a site that no longer exists must not silently write everywhere
            $outOfScope = array_keys($data);
            $data = [];

            return $outOfScope;
        }

        $pages = array_flip(TranslationHelper::getPagesOfSite($site));
        $outOfScope = [];

        foreach (array_keys($data) as $key) {
            // export keys are "table.uid.field", possibly with further parts for inline and flexform
            [$table, $uid] = array_pad(explode('.', (string)$key), 2, '');
            $pid = TranslationHelper::getPidOfRecord((string)$table, (int)$uid);

            if ($pid <= 0 || !isset($pages[$pid])) {
                $outOfScope[] = (string)$key;
                unset($data[$key]);
            }
        }

        return $outOfScope;
    }

    /**
     * @return array{errors: int, warnings: int, checked: int, findings: array<int, array{key: string, severity: string, type: string, arguments: array}>}
     */
    protected function summariseQa(array $qa): array
    {
        return [
            'errors' => $qa['errors'],
            'warnings' => $qa['warnings'],
            'checked' => $qa['checked'],
            'findings' => $qa['findings'],
        ];
    }

    protected function jsonResponse(array $data, int $statusCode): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse($statusCode)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    }
}

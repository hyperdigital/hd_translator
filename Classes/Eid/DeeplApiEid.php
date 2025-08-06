<?php
namespace Hyperdigital\HdTranslator\Eid;

use Hyperdigital\HdTranslator\Services\DeeplApiService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class DeeplApiEid
{
    public function fetchSupportedLanguages()
    {

        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);
        $languages = $deeplApiService->getAvailableLanguages(true);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($languages);
        exit;
    }

    public function translate()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['text']) || !isset($input['targetLang'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing text or targetLang']);
            exit;
        }

        $texts = $input['text'];

        if (!is_array($texts)) {
            $texts = [$texts];
        }

        $deeplApiService = GeneralUtility::makeInstance(DeeplApiService::class);
        $return = $deeplApiService->translateTexts($texts, $input['targetLang']);

        echo json_encode(['translations' => $return]);
        exit;
    }
}

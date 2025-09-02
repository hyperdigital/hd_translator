<?php
namespace Hyperdigital\HdTranslator\Services;


use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;

class DeeplApiService
{
    /**
     * @var string Deepl api version - https://developers.deepl.com/docs/getting-started/auth
     */
    protected $version = 'v2';

    /**
     * @var string Deepl api endpoint - https://developers.deepl.com/docs/getting-started/auth
     */
    protected $baseUrl = '';

    /**
     * @var string Deepl Api key - set over extension settings
     */
    protected $deeplApiKey = '';

    public function __construct(string $deeplApiKey = '' )
    {
        if (empty($deeplApiKey)) {
            $this->deeplApiKey = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('hd_translator', 'deeplApiKey') ?? '';
        } else {
            $this->deeplApiKey = $deeplApiKey;
        }

        if (substr($this->deeplApiKey, -3) == ':fx') {
            // Free version
            $this->baseUrl = 'https://api-free.deepl.com/'.$this->version.'/';
        } else {
            // Pro version
            $this->baseUrl = 'https://api.deepl.com/'.$this->version.'/';
        }
    }

    public function syncAvailableLanguages()
    {
        if (!empty($this->deeplApiKey)) {
            // otherwise, proxy DeepL
            $url = $this->baseUrl . 'languages?' . http_build_query([
                    'auth_key' => $this->deeplApiKey,
                    'type' => 'target',
                ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 200 || $response === false) {
                http_response_code(502);
                echo json_encode([
                    'error' => 'DeepL API error',
                    'details' => curl_error($ch),
                    'code' => $httpCode
                ]);
                exit;
            }
            curl_close($ch);

            $response = json_decode($response, true);
            foreach ($response as $row) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();

                $code = trim((string)($row['language'] ?? ''));
                $name = trim((string)($row['name']     ?? ''));

                if ($code === '' || $name === '') {
                    continue;
                }

                // 1) Check existence
                $existingUid = $queryBuilder
                    ->select('uid')
                    ->from('tx_hdtranslator_ai_languages')
                    ->where(
                        $queryBuilder->expr()->eq(
                            'language',
                            $queryBuilder->createNamedParameter($code)
                        )
                    )
                    ->executeQuery()->fetchAssociative();
                if ($existingUid && $existingUid['uid']) {
                    // 2a) Update
                    $queryBuilder
                        ->update('tx_hdtranslator_ai_languages')
                        ->where(
                            $queryBuilder->expr()->eq(
                                'uid',
                                $existingUid['uid']
                            )
                        )
                        ->set(
                            'name',
                            $name
                        )
                        ->executeQuery();
                } else {
                    // 2b) Insert
                    $queryBuilder
                        ->insert('tx_hdtranslator_ai_languages')
                        ->values([
                            'language' => $code,
                            'name' => $name
                        ])
                        ->executeQuery();
                }
            }
        }
    }

    public function getAvailableLanguages($cleaned = false)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();
        $select = ['*'];
        if ($cleaned) {
            $select = ['uid', 'language', 'name'];
        }
        $result = $queryBuilder
            ->select(...$select)
            ->from('tx_hdtranslator_ai_languages')
            ->orderBy('language')
            ->executeQuery();

        return $result->fetchAllAssociative();
    }

    public function getLanguageByCode($code)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_languages')->createQueryBuilder();
        $select = ['*'];

        $result = $queryBuilder
            ->select(...$select)
            ->from('tx_hdtranslator_ai_languages')
            ->orderBy('language')
            ->where(
                $queryBuilder->expr()->like('language', $queryBuilder->createNamedParameter($code))
            )
            ->executeQuery();

        return $result->fetchAssociative();
    }

    public function getAvailableLanguagesWithAmounts($cleaned = false)
    {
        /** @var \TYPO3\CMS\Core\Database\Query\QueryBuilder $qb */
        $qb = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_hdtranslator_ai_languages')
            ->createQueryBuilder();

// 1) Select your normal columns…
        $qb->select('l.uid', 'l.language', 'l.name')
// 2) …then add the COUNT() as a literal SQL fragment
            ->addSelectLiteral('COUNT(t.uid) AS translations_count')
// 3) From your languages table (aliased as "l")
            ->from('tx_hdtranslator_ai_languages', 'l')
// 4) LEFT JOIN onto your translations table (aliased as "t")
            ->leftJoin(
                'l',
                'tx_hdtranslator_ai_translation',
                't',
                $qb->expr()->eq('t.target_language', 'l.language')
            )
// 5) Group and order
            ->groupBy('l.uid')
            ->orderBy('l.language');
        $result = $qb->executeQuery();
        return $result->fetchAllAssociative();
    }

    public function getLocalTranslation($t, $targetLanguage)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslation = $queryBuilder
            ->select('translation')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($targetLanguage)
                ),
                $queryBuilder->expr()->eq(
                    'original_source',
                    $queryBuilder->createNamedParameter($t)
                )
            )
            ->executeQuery()->fetchAssociative();

        if ($existingTranslation) {
            return $existingTranslation['translation'];
        }

        return false;
    }

    public function deeplPost($postData)
    {
        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => $postData,
            ]
        ];
        $context = stream_context_create($options);
        $response = file_get_contents($this->baseUrl.'translate', false, $context);
        if ($response === FALSE) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to contact DeepL', 'message' => $response]);
            exit;
        }
        return json_decode($response, true);
    }

    public function setLocalTranslation($source, $translation, $targetLanguage)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $queryBuilder
            ->insert('tx_hdtranslator_ai_translation')
            ->values([
                'target_language' => $targetLanguage,
                'original_source' => $source,
                'original_translation' => $translation,
                'translation' => $translation
            ])
            ->executeQuery();
    }

    public function translateTexts(array $texts, string $targetLanguage): array
    {
        $return = [];
        $toTranslate = [];

        // 1) First pass: handle cached hits immediately, collect *unique* uncached strings
        foreach ($texts as $t) {
            if ($cached = $this->getLocalTranslation($t, $targetLanguage)) {
                $return[$t] = ['text' => $cached];
            } elseif (!in_array($t, $toTranslate, true)) {
                $toTranslate[] = $t;
            }
        }

        // 2) If there’s anything new to send, do it
        if (count($toTranslate) > 0) {
            // build the POST body using the unique list
            $postData = http_build_query([
                'auth_key'   => $this->deeplApiKey,
                'target_lang'=> $targetLanguage]);

            foreach ($toTranslate as $tTemp) {
                $postData .= '&text=' . urlencode($tTemp);
            }

            $data = $this->deeplPost($postData);

            // 3) Map each returned translation to its source
            $mapped = [];
            foreach ($data['translations'] as $i => $tr) {
                $sourceText    = $toTranslate[$i];
                $translatedText= $tr['text'] ?? $sourceText;
                $mapped[$sourceText] = $translatedText;

                // save locally
                $this->setLocalTranslation($sourceText, $translatedText, $targetLanguage);
            }

            // 4) Merge into return array for any uncached items
            foreach ($toTranslate as $orig) {
                $return[$orig] = ['text' => $mapped[$orig]];
            }
        }

        // 5) Re-order $return so it matches the original $texts order:
        $ordered = [];
        foreach ($texts as $t) {
            $ordered[$t] = $return[$t];
        }

        return $ordered;
    }

    public function getAllTranslationsForLanguage($language)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($language)
                )
            )
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function getTranslationByUid($uid)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid)
                )
            )
            ->executeQuery()->fetchAssociative();

        return $existingTranslations;
    }

    public function getTranslationsBySource($source)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->from('tx_hdtranslator_ai_translation')
            ->where(
                $queryBuilder->expr()->eq(
                    'original_source',
                    $queryBuilder->createNamedParameter($source)
                )
            )
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function getUniqueOriginals()
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $existingTranslations = $queryBuilder
            ->select('*')
            ->distinct()
            ->from('tx_hdtranslator_ai_translation')
            ->executeQuery()->fetchAllAssociative();

        return $existingTranslations;
    }

    public function removeAllTranslations($language)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_hdtranslator_ai_translation')->createQueryBuilder();

        $queryBuilder
            ->update('tx_hdtranslator_ai_translation')
            ->set('deleted', 1)
            ->where(
                $queryBuilder->expr()->eq(
                    'target_language',
                    $queryBuilder->createNamedParameter($language)
                )
            )
            ->executeQuery();

        return true;
    }
}
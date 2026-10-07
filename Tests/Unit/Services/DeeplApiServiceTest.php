<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\DeeplApiService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The part of translateTexts() that decides what has to be paid for: which strings are already
 * known, which go to DeepL, and how the answer is mapped back.
 */
final class DeeplApiServiceTest extends UnitTestCase
{
    /**
     * @param array<string, string> $cache source => translation
     */
    private function subject(array $cache, ?array $deeplAnswer = null): DeeplApiService
    {
        return new class('test:fx', $cache, $deeplAnswer) extends DeeplApiService {
            public array $sent = [];
            public array $stored = [];

            public function __construct(string $key, private array $cache, private ?array $deeplAnswer)
            {
                // the parent would read the extension configuration, which a unit test has not got
                $this->deeplApiKey = $key;
                $this->baseUrl = 'https://example.invalid/v2/';
            }

            protected function getLocalTranslations(array $sources, string $targetLanguage): array
            {
                return array_intersect_key($this->cache, array_flip($sources));
            }

            public function deeplPost($postData)
            {
                preg_match_all('/(?:^|&)text=([^&]*)/', (string)$postData, $matches);
                $this->sent = array_map('urldecode', $matches[1]);

                if ($this->deeplAnswer !== null) {
                    return ['translations' => $this->deeplAnswer];
                }

                return ['translations' => array_map(static fn(string $t): array => ['text' => 'xx:' . $t], $this->sent)];
            }

            public function setLocalTranslations(array $translations, string $targetLanguage): void
            {
                $this->stored = $this->stored + $translations;
            }
        };
    }

    #[Test]
    public function aCachedStringIsNeverSentToDeepl(): void
    {
        $subject = $this->subject(['Hello' => 'Hallo']);

        $result = $subject->translateTexts(['Hello'], 'DE');

        self::assertSame([], $subject->sent);
        self::assertSame('Hallo', $result['Hello']['text']);
    }

    #[Test]
    public function aRepeatedStringIsPaidForOnlyOnce(): void
    {
        // menus and footers repeat the same label many times on one page
        $subject = $this->subject([]);

        $result = $subject->translateTexts(['Home', 'Home', 'Contact', 'Home'], 'DE');

        self::assertSame(['Home', 'Contact'], $subject->sent);
        self::assertSame('xx:Home', $result['Home']['text']);
        self::assertSame('xx:Contact', $result['Contact']['text']);
    }

    #[Test]
    public function onlyTheUnknownStringsOfAMixedBatchAreSent(): void
    {
        $subject = $this->subject(['Known' => 'Bekannt']);

        $result = $subject->translateTexts(['Known', 'Unknown'], 'DE');

        self::assertSame(['Unknown'], $subject->sent);
        self::assertSame('Bekannt', $result['Known']['text']);
        self::assertSame('xx:Unknown', $result['Unknown']['text']);
    }

    #[Test]
    public function everyRequestedStringComesBack(): void
    {
        $subject = $this->subject(['Known' => 'Bekannt']);

        $result = $subject->translateTexts(['Known', 'Unknown'], 'DE');

        self::assertSame(['Known', 'Unknown'], array_keys($result));
    }

    #[Test]
    public function aShorterAnswerFromDeeplDoesNotShiftTheMapping(): void
    {
        // two strings sent, one translation returned: the second must not get the first's text
        $subject = $this->subject([], [['text' => 'Eins']]);

        $result = $subject->translateTexts(['One', 'Two'], 'DE');

        self::assertSame('Eins', $result['One']['text']);
        self::assertSame('Two', $result['Two']['text']);
        self::assertSame(['One' => 'Eins'], $subject->stored);
    }

    #[Test]
    public function whatDeeplReturnsIsPutIntoTheCache(): void
    {
        $subject = $this->subject([]);

        $subject->translateTexts(['New'], 'DE');

        self::assertSame(['New' => 'xx:New'], $subject->stored);
    }

    #[Test]
    public function nothingIsSentWhenEverythingIsCached(): void
    {
        $subject = $this->subject(['A' => 'a', 'B' => 'b']);

        $subject->translateTexts(['A', 'B', 'A'], 'DE');

        self::assertSame([], $subject->sent);
        self::assertSame([], $subject->stored);
    }

    #[Test]
    public function aStringWithoutALetterIsNeverPaidFor(): void
    {
        // page numbers, separators and currency signs come back unchanged anyway
        $subject = $this->subject([]);

        $result = $subject->translateTexts(['26', '|', '--', '12,50', 'Preis'], 'DE');

        self::assertSame(['Preis'], $subject->sent);
        self::assertSame('26', $result['26']['text']);
        self::assertSame('|', $result['|']['text']);
        self::assertSame('12,50', $result['12,50']['text']);
    }

    /**
     * @return object a service that records the size and shape of every call to DeepL
     */
    private function callRecorder(): object
    {
        return new class('test:fx') extends DeeplApiService {
            public array $calls = [];
            public array $bodySizes = [];

            public function __construct(string $key)
            {
                $this->deeplApiKey = $key;
                $this->baseUrl = 'https://example.invalid/v2/';
            }

            protected function getLocalTranslations(array $sources, string $targetLanguage): array
            {
                return [];
            }

            public function deeplPost($postData)
            {
                preg_match_all('/(?:^|&)text=([^&]*)/', (string)$postData, $matches);
                $texts = array_map('urldecode', $matches[1]);
                $this->calls[] = count($texts);
                $this->bodySizes[] = strlen((string)$postData);

                return ['translations' => array_map(static fn(string $t): array => ['text' => 'xx:' . $t], $texts)];
            }

            public function setLocalTranslations(array $translations, string $targetLanguage): void {}
        };
    }

    #[Test]
    public function manyShortStringsStillTravelInOneCall(): void
    {
        // DeepL limits a call by the size of the body, not by the number of texts in it, so
        // splitting by a count would only add round trips
        $subject = $this->callRecorder();

        $texts = [];
        for ($i = 0; $i < 300; $i++) {
            $texts[] = 'String ' . $i;
        }

        $result = $subject->translateTexts($texts, 'DE');

        self::assertSame([300], $subject->calls);
        self::assertCount(300, $result);
    }

    #[Test]
    public function aBatchTooLargeForOneCallIsSplitByItsSize(): void
    {
        $subject = $this->callRecorder();

        // 60 strings of 5000 characters, far beyond what one request may carry
        $texts = [];
        for ($i = 0; $i < 60; $i++) {
            $texts[] = str_pad('Text ' . $i . ' ', 5000, 'abcdefghij');
        }

        $result = $subject->translateTexts($texts, 'DE');

        self::assertGreaterThan(1, count($subject->calls));
        self::assertSame(60, array_sum($subject->calls), 'every string has to be sent exactly once');
        self::assertCount(60, $result);

        foreach ($subject->bodySizes as $size) {
            self::assertLessThanOrEqual(128 * 1024, $size, 'a call must stay under what DeepL accepts');
        }
    }

    #[Test]
    public function aSingleOversizedStringIsStillSent(): void
    {
        // it goes on its own rather than taking a whole batch down with it
        $subject = $this->callRecorder();

        $result = $subject->translateTexts([str_repeat('a', 5000), 'Short'], 'DE');

        self::assertSame(2, array_sum($subject->calls));
        self::assertCount(2, $result);
    }

    #[Test]
    public function everythingFreshIsWrittenInOneGo(): void
    {
        $subject = $this->subject([]);

        $subject->translateTexts(['One', 'Two'], 'DE');

        self::assertSame(['One' => 'xx:One', 'Two' => 'xx:Two'], $subject->stored);
    }
}

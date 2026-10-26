<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\AiRequestStatisticsService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * What the counter derives from a visitor. The storage itself is exercised against a database.
 */
final class AiRequestStatisticsServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private function hashOf(ServerRequest $request, string $day = ''): string
    {
        $subject = new class extends AiRequestStatisticsService {
            public string $fixedDay = '';
            public function hash(ServerRequest $request): string
            {
                return $this->visitorHash($request);
            }
            protected function today(): int
            {
                return $this->fixedDay !== '' ? (int)$this->fixedDay : parent::today();
            }
        };

        if ($day !== '') {
            $subject->fixedDay = $day;
        }

        return $subject->hash($request);
    }

    private function request(string $address, string $agent): ServerRequest
    {
        return (new ServerRequest('https://example.org/', 'GET', 'php://input', [], ['REMOTE_ADDR' => $address]))
            ->withHeader('User-Agent', $agent);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'test-encryption-key';
    }

    #[Test]
    public function theSameVisitorHashesTheSameWithinADay(): void
    {
        $a = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');
        $b = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');

        self::assertSame($a, $b);
    }

    #[Test]
    public function aDifferentVisitorHashesDifferently(): void
    {
        $a = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');
        $b = $this->hashOf($this->request('198.51.100.4', 'Firefox'), '20260101');

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function thesameVisitorHashesDifferentlyTheNextDay(): void
    {
        // this is what makes yesterday's rows untraceable
        $today = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');
        $tomorrow = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260102');

        self::assertNotSame($today, $tomorrow);
    }

    #[Test]
    public function theHashCarriesNeitherAddressNorAgent(): void
    {
        $hash = $this->hashOf($this->request('203.0.113.9', 'Mozilla/5.0 Firefox'), '20260101');

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertStringNotContainsString('203.0.113', $hash);
    }

    #[Test]
    public function theInstallationKeyIsPartOfTheHash(): void
    {
        // without it the hash of a known address could be computed by anybody
        $first = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');

        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'another-key';
        $second = $this->hashOf($this->request('203.0.113.9', 'Firefox'), '20260101');

        self::assertNotSame($first, $second);
    }

    #[Test]
    public function everyReportedWindowIsKnown(): void
    {
        self::assertSame(['today', 'week', 'month', 'total'], array_keys(AiRequestStatisticsService::WINDOWS));
        self::assertSame(0, AiRequestStatisticsService::WINDOWS['total'], 'total means everything still kept');
    }
}

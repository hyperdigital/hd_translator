<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\XlfService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The XLF file is the contract between this extension and whoever translates the content,
 * so the round trip is the behaviour worth pinning down.
 */
final class XlfServiceTest extends UnitTestCase
{
    private XlfService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new XlfService();
    }

    /**
     * @param array<string, string> $values
     */
    private function buildData(array $values, string $targetLanguage = 'de'): array
    {
        $data = [];
        foreach ($values as $key => $value) {
            $data[$key] = [
                'default' => $value['source'],
                $targetLanguage => $value['target'],
                '_label' => $value['label'] ?? '',
                '_html' => false,
                '_maxLength' => false,
                '_notes' => [],
                '_table_reference' => '',
            ];
        }

        return $data;
    }

    #[Test]
    public function exportProducesValidXliffWithSourceAndTarget(): void
    {
        $xlf = $this->subject->dataToXlf(
            $this->buildData(['tt_content.1.header' => ['source' => 'Hello', 'target' => 'Hallo']]),
            'de',
            'en'
        );

        $xml = simplexml_load_string($xlf);
        self::assertNotFalse($xml, 'the exported file has to be valid xml');
        self::assertSame('en', (string)$xml->file['source-language']);
        self::assertSame('de', (string)$xml->file['target-language']);

        $unit = $xml->file->body->children()[0];
        self::assertSame('tt_content.1.header', (string)$unit['id']);
        self::assertSame('Hello', (string)$unit->source);
        self::assertSame('Hallo', (string)$unit->target);
    }

    #[Test]
    public function roundTripKeepsKeysAndValues(): void
    {
        $data = $this->buildData([
            'tt_content.1.header' => ['source' => 'Hello', 'target' => 'Hallo'],
            'tt_content.1.bodytext' => ['source' => 'World', 'target' => 'Welt'],
        ]);

        $back = $this->subject->xlfToData($this->subject->dataToXlf($data, 'de', 'en'), ['default', 'de']);

        self::assertSame('Hallo', $back['tt_content.1.header']['de']);
        self::assertSame('Welt', $back['tt_content.1.bodytext']['de']);
    }

    #[Test]
    public function roundTripSurvivesLineBreaks(): void
    {
        // the multiline switch on the detail screen relies on this
        $data = $this->buildData(['a.key' => ['source' => "one\ntwo", 'target' => "eins\nzwei"]]);

        $back = $this->subject->xlfToData($this->subject->dataToXlf($data, 'de', 'en'), ['default', 'de']);

        self::assertSame("eins\nzwei", $back['a.key']['de']);
    }

    #[Test]
    public function roundTripSurvivesMarkupAndEntities(): void
    {
        $value = '<b>Bold</b> & "quoted" \'single\' <a href="?a=1&b=2">link</a>';
        $data = $this->buildData(['a.key' => ['source' => $value, 'target' => $value]]);

        $xlf = $this->subject->dataToXlf($data, 'de', 'en');
        self::assertNotFalse(simplexml_load_string($xlf), 'markup must not break the xml');

        $back = $this->subject->xlfToData($xlf, ['default', 'de']);
        self::assertSame($value, $back['a.key']['de']);
    }

    #[Test]
    public function roundTripSurvivesRightToLeftContent(): void
    {
        $data = $this->buildData(['a.key' => ['source' => 'Hello', 'target' => 'مرحبا بالعالم']], 'ar');

        $back = $this->subject->xlfToData($this->subject->dataToXlf($data, 'ar', 'en'), ['default', 'ar']);

        self::assertSame('مرحبا بالعالم', $back['a.key']['ar']);
    }

    #[Test]
    public function keysContainingDotsAreKeptIntact(): void
    {
        // export keys are dotted paths, they must not be split anywhere
        $key = 'tt_content.1.pi_flexform.settings.headline';
        $data = $this->buildData([$key => ['source' => 'S', 'target' => 'T']]);

        $back = $this->subject->xlfToData($this->subject->dataToXlf($data, 'de', 'en'), ['default', 'de']);

        self::assertArrayHasKey($key, $back);
        self::assertSame('T', $back[$key]['de']);
    }
}

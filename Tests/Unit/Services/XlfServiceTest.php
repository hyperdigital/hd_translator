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

    /**
     * Writing notes goes through LocalizationUtility, which registers a Locales singleton.
     */
    protected bool $resetSingletonInstances = true;

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
    public function xliff20IsWrittenWithUnitsAndSegments(): void
    {
        $xlf = $this->subject->dataToXlf(
            $this->buildData(['a.key' => ['source' => 'Hello', 'target' => 'Hallo']]),
            'de',
            'en',
            '',
            XlfService::VERSION_20
        );

        $xml = simplexml_load_string($xlf);
        self::assertNotFalse($xml);
        self::assertSame('2.0', (string)$xml['version']);
        self::assertSame('en', (string)$xml['srcLang']);
        self::assertSame('de', (string)$xml['trgLang']);
        self::assertStringContainsString('<unit', $xlf);
        self::assertStringContainsString('<segment', $xlf);
        self::assertStringNotContainsString('<trans-unit', $xlf);
    }

    #[Test]
    public function xliff20RoundTripKeepsValues(): void
    {
        $data = $this->buildData([
            'a.key' => ['source' => 'Hello', 'target' => 'Hallo'],
            'b.key' => ['source' => "one\ntwo", 'target' => 'مرحبا'],
        ]);

        $xlf = $this->subject->dataToXlf($data, 'de', 'en', '', XlfService::VERSION_20);
        $back = $this->subject->xlfToData($xlf, ['default', 'de']);

        self::assertSame('Hallo', $back['a.key']['de']);
        self::assertSame('مرحبا', $back['b.key']['de']);
        self::assertSame("one\ntwo", $back['b.key']['default']);
    }

    #[Test]
    public function xliff20StateReflectsWhetherSomethingWasTranslated(): void
    {
        $xlf = $this->subject->dataToXlf(
            $this->buildData([
                'translated' => ['source' => 'Hello', 'target' => 'Hallo'],
                'untouched' => ['source' => 'Hello', 'target' => 'Hello'],
            ]),
            'de',
            'en',
            '',
            XlfService::VERSION_20
        );

        $parsed = $this->subject->parse($xlf);

        self::assertSame(XlfService::STATE_TRANSLATED, $parsed['translated']['state']);
        self::assertSame(XlfService::STATE_INITIAL, $parsed['untouched']['state']);
    }

    #[Test]
    public function xliff20ApprovalFollowsTheSegmentState(): void
    {
        $template = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en" trgLang="de">'
            . '<file id="f1"><unit id="a"><segment state="%s"><source>Hello</source><target>Hallo</target></segment></unit></file>'
            . '</xliff>';

        foreach ([
            XlfService::STATE_INITIAL => false,
            XlfService::STATE_TRANSLATED => false,
            XlfService::STATE_REVIEWED => true,
            XlfService::STATE_FINAL => true,
        ] as $state => $expected) {
            $parsed = $this->subject->parse(sprintf($template, $state));
            self::assertSame($expected, $parsed['a']['approved'], 'state ' . $state);
        }
    }

    #[Test]
    public function theVersionIsDetectedFromTheFile(): void
    {
        $v12 = $this->subject->dataToXlf($this->buildData(['a' => ['source' => 'S', 'target' => 'T']]), 'de', 'en');
        $v20 = $this->subject->dataToXlf($this->buildData(['a' => ['source' => 'S', 'target' => 'T']]), 'de', 'en', '', XlfService::VERSION_20);

        // both are read without the caller having to say which one it is
        self::assertSame('T', $this->subject->parse($v12)['a']['target']);
        self::assertSame('T', $this->subject->parse($v20)['a']['target']);
    }

    #[Test]
    public function maxLengthHintSurvivesTheRoundTripInXliff12(): void
    {
        $data = $this->buildData(['a' => ['source' => 'S', 'target' => 'T']]);
        $data['a']['_maxLength'] = 255;

        $parsed = $this->subject->parse($this->subject->dataToXlf($data, 'de', 'en'));

        self::assertSame(255, $parsed['a']['maxLength']);
    }

    #[Test]
    public function malformedInputYieldsNoEntriesInsteadOfCrashing(): void
    {
        self::assertSame([], $this->subject->parse('<xliff><file><body><trans-unit'));
        self::assertSame([], $this->subject->parse(''));
        self::assertSame([], $this->subject->xlfToData('not xml at all', ['default', 'de']));
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

    #[Test]
    public function aUnitWithoutAnApprovedAttributeCountsAsApproved(): void
    {
        // TYPO3 reads it the same way, only an explicit approved="no" holds a label back
        $xlf = '<?xml version="1.0"?><xliff version="1.2"><file source-language="en" target-language="de" '
            . 'original="messages" datatype="plaintext"><body>'
            . '<trans-unit id="a"><source>A</source><target>A-de</target></trans-unit>'
            . '</body></file></xliff>';

        $parsed = $this->subject->parse($xlf);

        self::assertTrue($parsed['a']['approved']);
        self::assertSame(XlfService::STATE_FINAL, $parsed['a']['state']);
    }

    #[Test]
    public function anExplicitlyUnapprovedUnitIsNotApproved(): void
    {
        $xlf = '<?xml version="1.0"?><xliff version="1.2"><file source-language="en" target-language="de" '
            . 'original="messages" datatype="plaintext"><body>'
            . '<trans-unit id="a" approved="no"><source>A</source><target>A-de</target></trans-unit>'
            . '</body></file></xliff>';

        $parsed = $this->subject->parse($xlf);

        self::assertFalse($parsed['a']['approved']);
    }

    #[Test]
    public function onlyReviewedAndFinalCountAsApproved(): void
    {
        self::assertFalse(XlfService::isApprovedState(XlfService::STATE_INITIAL));
        self::assertFalse(XlfService::isApprovedState(XlfService::STATE_TRANSLATED));
        self::assertTrue(XlfService::isApprovedState(XlfService::STATE_REVIEWED));
        self::assertTrue(XlfService::isApprovedState(XlfService::STATE_FINAL));
    }

    #[Test]
    public function anExplicitStateIsKeptInsteadOfBeingDerived(): void
    {
        $data = ['key' => ['default' => 'Source', 'de' => 'Ziel', '_state' => XlfService::STATE_REVIEWED]];

        $back = $this->subject->parse(
            (string)$this->subject->dataToXlf($data, 'de', 'en', '', XlfService::VERSION_20)
        );

        self::assertSame(XlfService::STATE_REVIEWED, $back['key']['state']);
        self::assertTrue($back['key']['approved']);
    }

    #[Test]
    public function anExplicitStateIsWrittenIntoXliff12AsApprovedAndState(): void
    {
        $notApproved = (string)$this->subject->dataToXlf(
            ['key' => ['default' => 'Source', 'de' => 'Ziel', '_state' => XlfService::STATE_TRANSLATED]],
            'de',
            'en'
        );

        self::assertStringContainsString('approved="no"', $notApproved);
        self::assertStringContainsString('state="translated"', $notApproved);
        self::assertFalse($this->subject->parse($notApproved)['key']['approved']);

        $approved = (string)$this->subject->dataToXlf(
            ['key' => ['default' => 'Source', 'de' => 'Ziel', '_state' => XlfService::STATE_FINAL]],
            'de',
            'en'
        );

        self::assertStringContainsString('approved="yes"', $approved);
    }

    #[Test]
    public function anEntryWithoutAStateDoesNotGetAnApprovedAttribute(): void
    {
        // adding approved="no" to files that never had it would hide labels that work today
        $xlf = (string)$this->subject->dataToXlf(
            ['key' => ['default' => 'Source', 'de' => 'Ziel']],
            'de',
            'en'
        );

        self::assertStringNotContainsString('approved=', $xlf);
    }

    #[Test]
    public function xliff20UnitsCarryTheKeyInNameAsWellAsInId(): void
    {
        // the Symfony loader keys a 2.0 unit by "name" and only falls back to "id"
        $xlf = (string)$this->subject->dataToXlf(
            ['my.key' => ['default' => 'Source', 'de' => 'Ziel', '_label' => 'Human label']],
            'de',
            'en',
            '',
            XlfService::VERSION_20
        );

        self::assertStringContainsString('id="my.key"', $xlf);
        self::assertStringContainsString('name="my.key"', $xlf);
        self::assertStringNotContainsString('name="Human label"', $xlf);
    }
}

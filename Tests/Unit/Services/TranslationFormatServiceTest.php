<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\TranslationFormatService;
use Hyperdigital\HdTranslator\Services\XlfService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationFormatServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private TranslationFormatService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TranslationFormatService(new XlfService());
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return [
            'tt_content:12:header' => ['default' => 'Our services', 'de' => 'Unsere Leistungen'],
            'tt_content:12:bodytext' => ['default' => '<p>Hello %s</p>', 'de' => '<p>Hallo %s</p>'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyExportFormat(): array
    {
        return [
            'xliff 1.2' => [TranslationFormatService::FORMAT_XLF_12],
            'xliff 2.0' => [TranslationFormatService::FORMAT_XLF_20],
            'gettext' => [TranslationFormatService::FORMAT_PO],
            'json' => [TranslationFormatService::FORMAT_JSON],
            'yaml' => [TranslationFormatService::FORMAT_YAML],
            'csv' => [TranslationFormatService::FORMAT_CSV],
        ];
    }

    #[Test]
    #[DataProvider('everyExportFormat')]
    public function everyExportFormatSurvivesTheRoundTrip(string $format): void
    {
        $dumped = $this->subject->dump($this->data(), $format, 'de', 'en');
        $back = $this->subject->load($dumped, $this->subject->getFileExtension($format));

        self::assertSame('Unsere Leistungen', $back['tt_content:12:header']['target']);
        self::assertSame('<p>Hallo %s</p>', $back['tt_content:12:bodytext']['target']);
    }

    #[Test]
    public function xliffKeepsTheSourceWhileTheOtherFormatsCarryTheTranslationOnly(): void
    {
        $xliff = $this->subject->load(
            $this->subject->dump($this->data(), TranslationFormatService::FORMAT_XLF_12, 'de', 'en'),
            'xlf'
        );
        $gettext = $this->subject->load(
            $this->subject->dump($this->data(), TranslationFormatService::FORMAT_PO, 'de', 'en'),
            'po'
        );

        self::assertSame('Our services', $xliff['tt_content:12:header']['source']);
        self::assertSame('', $gettext['tt_content:12:header']['source']);
    }

    #[Test]
    public function anUnknownFormatFallsBackToXliff12(): void
    {
        $dumped = $this->subject->dump($this->data(), 'something-else', 'de', 'en');

        self::assertStringContainsString('<xliff version="1.2"', $dumped);
        self::assertSame('xlf', $this->subject->getFileExtension('something-else'));
    }

    #[Test]
    public function theImportAcceptsXliffAndTheSymfonyFormats(): void
    {
        $extensions = $this->subject->getImportExtensions();

        self::assertContains('xlf', $extensions);
        self::assertContains('po', $extensions);
        self::assertContains('csv', $extensions);
        self::assertTrue($this->subject->canLoad('yml'));
        self::assertTrue($this->subject->canLoad('YAML'));
        self::assertFalse($this->subject->canLoad('docx'));
    }

    #[Test]
    public function aFileTheLoaderCannotReadYieldsNoEntriesInsteadOfCrashing(): void
    {
        self::assertSame([], $this->subject->load('{ not json', 'json'));
        self::assertSame([], $this->subject->load('anything', 'docx'));
    }

    #[Test]
    public function onlyTheKnownFormatsAreOfferedForExport(): void
    {
        $formats = $this->subject->getExportFormats();

        self::assertArrayHasKey(TranslationFormatService::FORMAT_XLF_12, $formats);
        self::assertArrayHasKey(TranslationFormatService::FORMAT_CSV, $formats);
        self::assertTrue($this->subject->isSupportedExportFormat(TranslationFormatService::FORMAT_PO));
        self::assertFalse($this->subject->isSupportedExportFormat('docx'));
    }

    #[Test]
    public function importDataDropsEntriesWithoutATranslation(): void
    {
        $data = $this->subject->toImportData([
            'filled' => ['target' => 'Hallo'],
            'empty' => ['target' => '   '],
        ]);

        self::assertSame(['filled' => 'Hallo'], $data);
    }

    #[Test]
    public function importDataCanBeTakenFromTheSourceInstead(): void
    {
        $data = $this->subject->toImportData(
            ['key' => ['source' => 'Hello', 'target' => 'Hallo']],
            'source'
        );

        self::assertSame(['key' => 'Hello'], $data);
    }
}

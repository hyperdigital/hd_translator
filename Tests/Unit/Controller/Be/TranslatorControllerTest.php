<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Controller\Be;

use Hyperdigital\HdTranslator\Controller\Be\TranslatorController;
use Hyperdigital\HdTranslator\Services\XlfService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The writer of the override files, which is what the static string editing screen saves through.
 */
final class TranslatorControllerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private TranslatorController $subject;

    protected function setUp(): void
    {
        parent::setUp();
        // the writer needs none of the injected services
        $this->subject = (new \ReflectionClass(TranslatorController::class))->newInstanceWithoutConstructor();
    }

    private function write(array $data, string $language): string
    {
        $method = new \ReflectionMethod(TranslatorController::class, 'dataToXlf');

        return (string)$method->invoke($this->subject, 'probe', $language, $data);
    }

    private function wouldEmpty(string $path, string $content): bool
    {
        $method = new \ReflectionMethod(TranslatorController::class, 'wouldEmptyTheFile');

        return (bool)$method->invoke($this->subject, $path, $content);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceLanguageKeys(): array
    {
        return ['default' => ['default'], 'en' => ['en']];
    }

    #[Test]
    #[DataProvider('sourceLanguageKeys')]
    public function theFileOfTheSourceLanguageDeclaresNoTargetLanguage(string $language): void
    {
        // TYPO3 decides from that attribute whether to read <source> or <target>. The source
        // language file has no <target>, so declaring it makes every label resolve to an empty
        // string: the editing screen comes up blank and saving writes the blanks back.
        $xlf = $this->write(['a.key' => [$language => 'Skip links']], $language);

        self::assertStringNotContainsString('target-language', $xlf);
        self::assertStringContainsString('<source>Skip links</source>', $xlf);
    }

    #[Test]
    public function aTranslationStillDeclaresItsTargetLanguage(): void
    {
        $xlf = $this->write(['a.key' => ['default' => 'Skip links', 'de' => 'Sprunglinks']], 'de');

        self::assertStringContainsString('target-language="de"', $xlf);
        self::assertStringContainsString('<target>Sprunglinks</target>', $xlf);
    }

    #[Test]
    public function aReviewStateIsWrittenAsApprovedAndState(): void
    {
        $xlf = $this->write(
            ['a.key' => ['default' => 'Source', 'de' => 'Ziel', '_state' => XlfService::STATE_TRANSLATED]],
            'de'
        );

        self::assertStringContainsString('approved="no"', $xlf);
        self::assertStringContainsString('state="translated"', $xlf);
    }

    #[Test]
    public function anEntryWithoutAStateGetsNoApprovedAttribute(): void
    {
        $xlf = $this->write(['a.key' => ['default' => 'Source', 'de' => 'Ziel']], 'de');

        self::assertStringNotContainsString('approved=', $xlf);
    }

    #[Test]
    public function aSaveThatWouldEmptyEveryLabelIsRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hdt');
        file_put_contents($path, $this->write(['a.key' => ['default' => 'Skip links']], 'default'));

        $allEmpty = $this->write(['a.key' => ['default' => '']], 'default');

        self::assertTrue($this->wouldEmpty($path, $allEmpty));

        unlink($path);
    }

    #[Test]
    public function anOrdinarySaveIsNotRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hdt');
        file_put_contents($path, $this->write(['a.key' => ['default' => 'Skip links']], 'default'));

        $changed = $this->write(['a.key' => ['default' => 'Skip the links']], 'default');

        self::assertFalse($this->wouldEmpty($path, $changed));

        unlink($path);
    }

    #[Test]
    public function clearingOneLabelOfSeveralIsNotRefused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hdt');
        file_put_contents($path, $this->write([
            'a.key' => ['default' => 'Skip links'],
            'b.key' => ['default' => 'Header'],
        ], 'default'));

        $oneCleared = $this->write([
            'a.key' => ['default' => ''],
            'b.key' => ['default' => 'Header'],
        ], 'default');

        self::assertFalse($this->wouldEmpty($path, $oneCleared));

        unlink($path);
    }

    #[Test]
    public function thereIsNothingToProtectWhenTheFileDoesNotExistYet(): void
    {
        self::assertFalse($this->wouldEmpty('/does/not/exist.xlf', $this->write(['a' => ['default' => '']], 'default')));
    }
}

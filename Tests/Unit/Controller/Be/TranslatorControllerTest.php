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

    #[Test]
    #[DataProvider('sourceLanguageKeys')]
    public function theSourceLanguageCarriesNoReviewState(string $language): void
    {
        // it is a template of sources, there is no target to approve, which is why the editing
        // screen does not offer a state there either
        $xlf = $this->write(
            ['a.key' => [$language => 'Skip links', '_state' => XlfService::STATE_TRANSLATED]],
            $language
        );

        self::assertStringNotContainsString('approved=', $xlf);
        self::assertStringNotContainsString('state=', $xlf);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $units id => [target, state]
     */
    private function writeOverride(string $language, string $key, array $units): string
    {
        $storage = \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/hdt-test/';
        if (!is_dir($storage)) {
            mkdir($storage, 0777, true);
        }

        $body = '';
        foreach ($units as $id => [$target, $state]) {
            $approved = XlfService::isApprovedState($state) ? 'yes' : 'no';
            $body .= sprintf(
                '<trans-unit id="%s" approved="%s"><source>%s EN</source><target state="%s">%s</target></trans-unit>',
                $id,
                $approved,
                $id,
                $state,
                $target
            );
        }

        $path = $storage . $language . '.' . $key . '.xlf';
        file_put_contents(
            $path,
            '<?xml version="1.0"?><xliff version="1.2"><file source-language="en" target-language="' . $language
            . '" original="messages" datatype="plaintext"><header/><body>' . $body . '</body></file></xliff>'
        );

        $property = new \ReflectionProperty(TranslatorController::class, 'storage');
        $property->setValue($this->subject, $storage);

        return $path;
    }

    private function overlay(array $data, string $language, string $key): array
    {
        $method = new \ReflectionMethod(TranslatorController::class, 'overlayStoredOverride');
        $method->invokeArgs($this->subject, [&$data, $language, $key]);

        return $data;
    }

    /**
     * @param string $live what LanguageService resolved, which is what the frontend shows
     */
    private function resolved(string $live): array
    {
        return [0 => [
            'source' => 'Source',
            'target' => $live,
            'live' => $live,
            'state' => XlfService::STATE_FINAL,
            'pending' => false,
        ]];
    }

    #[Test]
    public function anUnapprovedTranslationIsMarkedPendingAndKeepsTheLiveValueBesideIt(): void
    {
        // the frontend is showing the translation the extension ships, because the stored one is
        // held back by the approval gate
        $path = $this->writeOverride('de', 'probe', ['alpha' => ['Alpha DE pending', XlfService::STATE_TRANSLATED]]);

        $data = $this->overlay(['de' => ['alpha' => $this->resolved('Alpha DE shipped')]], 'de', 'probe');

        self::assertSame('Alpha DE pending', $data['de']['alpha'][0]['target']);
        self::assertSame('Alpha DE shipped', $data['de']['alpha'][0]['live']);
        self::assertSame(XlfService::STATE_TRANSLATED, $data['de']['alpha'][0]['state']);
        self::assertTrue($data['de']['alpha'][0]['pending']);

        unlink($path);
    }

    #[Test]
    public function anApprovedTranslationIsNotPending(): void
    {
        $path = $this->writeOverride('de', 'probe', ['beta' => ['Beta DE reviewed', XlfService::STATE_REVIEWED]]);

        $data = $this->overlay(['de' => ['beta' => $this->resolved('Beta DE reviewed')]], 'de', 'probe');

        self::assertSame('Beta DE reviewed', $data['de']['beta'][0]['live']);
        self::assertFalse($data['de']['beta'][0]['pending']);

        unlink($path);
    }

    #[Test]
    public function theEditorKeepsTheStoredTextEvenWhileItIsHeldBack(): void
    {
        // the whole reason the stored file is read at all: LanguageService would answer with the
        // fallback, and saving that back would overwrite the translation with it
        $path = $this->writeOverride('de', 'probe', ['gamma' => ['Gamma DE pending', XlfService::STATE_TRANSLATED]]);

        $data = $this->overlay(['de' => ['gamma' => $this->resolved('Gamma EN')]], 'de', 'probe');

        self::assertSame('Gamma DE pending', $data['de']['gamma'][0]['target']);
        self::assertSame('Gamma EN', $data['de']['gamma'][0]['live']);
        self::assertTrue($data['de']['gamma'][0]['pending']);

        unlink($path);
    }

    #[Test]
    public function theSourceLanguageHasNothingToOverlay(): void
    {
        $data = $this->overlay(['en' => ['alpha' => $this->resolved('Alpha EN')]], 'en', 'probe');

        self::assertFalse($data['en']['alpha'][0]['pending']);
    }
}

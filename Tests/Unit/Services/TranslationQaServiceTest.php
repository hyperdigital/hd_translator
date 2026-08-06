<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\TranslationQaService;
use Hyperdigital\HdTranslator\Services\XlfService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class TranslationQaServiceTest extends UnitTestCase
{
    private TranslationQaService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TranslationQaService();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function entry(string $source, string $target, array $overrides = []): array
    {
        return ['key' => array_merge([
            'source' => $source,
            'target' => $target,
            'state' => XlfService::STATE_FINAL,
            'approved' => true,
            'maxLength' => null,
            'notes' => [],
            'html' => false,
        ], $overrides)];
    }

    /**
     * @return array<int, string>
     */
    private function typesOf(array $result): array
    {
        return array_map(static fn(array $finding): string => $finding['type'], $result['findings']);
    }

    #[Test]
    public function aCleanTranslationProducesNoFindings(): void
    {
        $result = $this->subject->check($this->entry('Hello world', 'Hallo Welt'));

        self::assertSame([], $result['findings']);
        self::assertSame(0, $result['errors']);
        self::assertSame(1, $result['checked']);
    }

    #[Test]
    public function aTargetLongerThanTheAllowedLengthIsAnError(): void
    {
        $result = $this->subject->check($this->entry('Short', str_repeat('a', 12), ['maxLength' => 10]));

        self::assertSame(['maxLengthExceeded'], $this->typesOf($result));
        self::assertSame(TranslationQaService::SEVERITY_ERROR, $result['findings'][0]['severity']);
        self::assertSame([12, 10], $result['findings'][0]['arguments']);
        self::assertSame(['key'], $result['failedKeys']);
    }

    #[Test]
    public function aLengthWithinTheLimitPassesAndIsCountedInCharactersNotBytes(): void
    {
        // five multibyte characters, ten bytes
        $result = $this->subject->check($this->entry('Short', 'ěščřž', ['maxLength' => 5]));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function aLostPlaceholderIsAnError(): void
    {
        $result = $this->subject->check($this->entry('Welcome %s, you have %d messages', 'Willkommen %s'));

        self::assertSame(['placeholderMismatch'], $this->typesOf($result));
        self::assertSame('%d', $result['findings'][0]['arguments'][0]);
        self::assertSame('-', $result['findings'][0]['arguments'][1]);
    }

    #[Test]
    public function anInventedPlaceholderIsAnError(): void
    {
        $result = $this->subject->check($this->entry('Welcome', 'Willkommen %s'));

        self::assertSame(['placeholderMismatch'], $this->typesOf($result));
        self::assertSame('-', $result['findings'][0]['arguments'][0]);
        self::assertSame('%s', $result['findings'][0]['arguments'][1]);
    }

    #[Test]
    public function droppingOneOfTwoIdenticalPlaceholdersIsStillFound(): void
    {
        $result = $this->subject->check($this->entry('%s and %s', 'nur %s'));

        self::assertSame(['placeholderMismatch'], $this->typesOf($result));
    }

    #[Test]
    public function reorderedPlaceholdersAreAccepted(): void
    {
        $result = $this->subject->check($this->entry('%1$s of %2$s', '%2$s, davon %1$s'));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function anEscapedPercentIsNotMistakenForAPlaceholder(): void
    {
        $result = $this->subject->check($this->entry('100%% done', '100%% erledigt'));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function bracePlaceholdersAreCheckedToo(): void
    {
        $result = $this->subject->check($this->entry('Hello {name}', 'Hallo {nme}'));

        self::assertSame(['placeholderMismatch'], $this->typesOf($result));
    }

    #[Test]
    public function unbalancedMarkupIsAnError(): void
    {
        $result = $this->subject->check($this->entry('<p>Hello</p>', '<p>Hallo'));

        self::assertContains('markupUnbalanced', $this->typesOf($result));
        self::assertSame(TranslationQaService::SEVERITY_ERROR, $result['findings'][0]['severity']);
    }

    #[Test]
    public function crossedMarkupIsAnError(): void
    {
        $result = $this->subject->check($this->entry('<p><b>Hi</b></p>', '<p><b>Hallo</p></b>'));

        self::assertContains('markupUnbalanced', $this->typesOf($result));
    }

    #[Test]
    public function voidElementsDoNotCountAsUnbalanced(): void
    {
        $result = $this->subject->check($this->entry('Line<br>break', 'Zeilen<br>umbruch'));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function selfClosingElementsDoNotCountAsUnbalanced(): void
    {
        $result = $this->subject->check($this->entry('<img src="a.png" />x', '<img src="a.png" />y'));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function aBalancedButDifferentSetOfTagsIsOnlyAWarning(): void
    {
        $result = $this->subject->check($this->entry('<p>Hello <b>you</b></p>', '<p>Hallo du</p>'));

        self::assertSame(['markupMismatch'], $this->typesOf($result));
        self::assertSame(TranslationQaService::SEVERITY_WARNING, $result['findings'][0]['severity']);
        self::assertSame('<b>', $result['findings'][0]['arguments'][0]);
        self::assertSame([], $result['failedKeys']);
    }

    #[Test]
    public function anEmptyTargetIsReportedAsSkipped(): void
    {
        $result = $this->subject->check($this->entry('Hello', '   '));

        self::assertSame(['emptyTarget'], $this->typesOf($result));
        self::assertSame(TranslationQaService::SEVERITY_WARNING, $result['findings'][0]['severity']);
    }

    #[Test]
    public function anEntryEmptyOnBothSidesIsNotWorthReporting(): void
    {
        $result = $this->subject->check($this->entry('', ''));

        self::assertSame([], $result['findings']);
    }

    #[Test]
    public function aTargetIdenticalToTheSourceIsAWarning(): void
    {
        $result = $this->subject->check($this->entry('Hello world', 'Hello world'));

        self::assertSame(['identicalToSource'], $this->typesOf($result));
    }

    #[Test]
    public function anUntouchedXliff20EntryIsReported(): void
    {
        $result = $this->subject->check(
            $this->entry('Hello', 'Hallo', ['state' => XlfService::STATE_INITIAL])
        );

        self::assertSame(['notTranslated'], $this->typesOf($result));
    }

    #[Test]
    public function severalProblemsInOneEntryAreAllReported(): void
    {
        $result = $this->subject->check(
            $this->entry('<b>%s</b> items', '<b>Stücke', ['maxLength' => 3])
        );

        $types = $this->typesOf($result);
        self::assertContains('maxLengthExceeded', $types);
        self::assertContains('placeholderMismatch', $types);
        self::assertContains('markupUnbalanced', $types);
        self::assertSame(['key'], $result['failedKeys']);
    }

    #[Test]
    public function findingsCarryTheKeyTheyBelongTo(): void
    {
        $result = $this->subject->check([
            'first' => ['source' => 'Hello', 'target' => ''],
            'second' => ['source' => '%s', 'target' => 'nope'],
        ]);

        self::assertSame('first', $result['findings'][0]['key']);
        self::assertSame('second', $result['findings'][1]['key']);
        self::assertSame(['second'], $result['failedKeys']);
        self::assertSame(1, $result['errors']);
        self::assertSame(1, $result['warnings']);
    }

    #[Test]
    public function resultsOfSeveralFilesAreMergedWithoutDuplicatingKeys(): void
    {
        $first = $this->subject->check($this->entry('%s', 'nope'));
        $second = $this->subject->check($this->entry('%d', 'auch nicht'));

        $merged = $this->subject->merge($this->subject->merge([], $first), $second);

        self::assertSame(2, $merged['errors']);
        self::assertSame(2, $merged['checked']);
        self::assertSame(['key'], $merged['failedKeys']);
        self::assertCount(2, $merged['findings']);
    }
}

<?php
declare(strict_types=1);

namespace Hyperdigital\HdTranslator\Tests\Unit\Services;

use Hyperdigital\HdTranslator\Services\LanguageDirectionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LanguageDirectionServiceTest extends UnitTestCase
{
    public static function directionProvider(): \Generator
    {
        yield 'arabic is right to left' => ['ar', 'rtl'];
        yield 'hebrew is right to left' => ['he', 'rtl'];
        yield 'legacy hebrew code' => ['iw', 'rtl'];
        yield 'persian is right to left' => ['fa', 'rtl'];
        yield 'german is left to right' => ['de', 'ltr'];
        yield 'english is left to right' => ['en', 'ltr'];
        yield 'default is left to right' => ['default', 'ltr'];
        yield 'unknown code falls back' => ['zz', 'ltr'];
        yield 'empty string falls back' => ['', 'ltr'];
    }

    #[Test]
    #[DataProvider('directionProvider')]
    public function getDirectionReturnsExpectedDirection(string $language, string $expected): void
    {
        self::assertSame($expected, LanguageDirectionService::getDirection($language));
    }

    public static function regionProvider(): \Generator
    {
        yield 'hyphen region' => ['ar-EG', true];
        yield 'underscore region' => ['ar_EG', true];
        yield 'uppercase' => ['AR', true];
        yield 'padded' => ['  ar  ', true];
        yield 'latin with region' => ['pt-BR', false];
    }

    #[Test]
    #[DataProvider('regionProvider')]
    public function regionAndCaseAreNormalized(string $language, bool $expected): void
    {
        self::assertSame($expected, LanguageDirectionService::isRightToLeft($language));
    }

    #[Test]
    public function kurmanjiKurdishIsNotTreatedAsRightToLeft(): void
    {
        // "ku" is written in latin script, only sorani "ckb" is right to left.
        // A wrong direction is worse than the inherited one.
        self::assertFalse(LanguageDirectionService::isRightToLeft('ku'));
        self::assertTrue(LanguageDirectionService::isRightToLeft('ckb'));
    }
}

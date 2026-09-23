<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Machine;

use Eekes\SuluTranslationsBundle\Application\Machine\PlaceholderProtector;
use PHPUnit\Framework\TestCase;

class PlaceholderProtectorTest extends TestCase
{
    public function testItHidesAndRestoresPlaceholders(): void
    {
        $protector = new PlaceholderProtector();

        [$protected, $map] = $protector->protect('Hello %name%, you have {count} messages');

        self::assertStringNotContainsString('%name%', $protected);
        self::assertStringNotContainsString('{count}', $protected);
        self::assertCount(2, $map);

        self::assertSame(
            'Hallo %name%, je hebt {count} berichten',
            $protector->restore(\str_replace(['Hello', 'you have', 'messages'], ['Hallo', 'je hebt', 'berichten'], $protected), $map),
        );
    }

    public function testItToleratesATagTheEngineReformatted(): void
    {
        $protector = new PlaceholderProtector();
        [, $map] = $protector->protect('Hello %name%');

        // DeepL and friends return `<x id="0"></x>`, single quotes, or an extra space. A
        // literal string comparison would throw away a perfectly good translation.
        self::assertSame('Hallo %name%', $protector->restore('Hallo <x id="0"></x>', $map));
        self::assertSame('Hallo %name%', $protector->restore("Hallo <x id='0'/>", $map));
    }

    public function testItRefusesAResultThatLostAPlaceholder(): void
    {
        $protector = new PlaceholderProtector();
        [, $map] = $protector->protect('Hello %name%');

        // Discarding is the right answer: the write path would refuse the value anyway, and a
        // half-translated string on a live page is harder to spot than an untranslated one.
        self::assertNull($protector->restore('Hallo', $map));
    }

    public function testAValueWithRegexSpecialCharactersSurvives(): void
    {
        $protector = new PlaceholderProtector();
        [$protected, $map] = $protector->protect('Pay %amount%');
        $map = \array_map(static fn (): string => '$1\\2', $map);

        self::assertSame('Betaal $1\\2', $protector->restore(\str_replace('Pay', 'Betaal', $protected), $map));
    }
}

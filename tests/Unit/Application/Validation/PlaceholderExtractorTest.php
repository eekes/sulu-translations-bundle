<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Validation;

use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaceholderExtractorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideMessages')]
    public function testExtract(?string $message, array $expected): void
    {
        self::assertSame($expected, (new PlaceholderExtractor())->extract($message));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideTokens')]
    public function testExtractTokens(?string $message, array $expected): void
    {
        self::assertSame($expected, (new PlaceholderExtractor())->extractTokens($message));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: list<string>}>
     */
    public static function provideTokens(): iterable
    {
        yield 'nothing' => ['Plain text', []];
        yield 'null' => [null, []];

        // What comes back is what an editor can type, not the argument name: `count` is not
        // something anybody can paste into a translation, `%count%` is.
        yield 'symfony style' => ['Hello %name%, you have %count% messages', ['%name%', '%count%']];
        yield 'interpolation style' => ['Hello {{name}}', ['{{ name }}']];
        yield 'icu argument' => ['Hello {name}', ['{name}']];

        // The head of a plural has no self-contained form, so the argument on its own is the
        // honest minimum.
        yield 'icu plural' => ['{count, plural, one {# item} other {# items}}', ['{count}']];

        // Document order, whatever notation each one is written in: the chips sit under the
        // text they belong to.
        yield 'mixed notations keep their order' => ['{total} of %count%', ['{total}', '%count%']];

        // One argument, one chip - showing `%count%` and `{count}` side by side would read as
        // two things to fill in.
        yield 'one argument in two notations' => ['%count% or {count}', ['%count%']];
    }

    public function testNamesOfAStoredTokenList(): void
    {
        $extractor = new PlaceholderExtractor();

        self::assertSame(['count', 'name'], $extractor->namesOf(['%name%', '{count}']));
        self::assertSame([], $extractor->namesOf([]));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: list<string>}>
     */
    public static function provideMessages(): iterable
    {
        yield 'nothing' => ['Plain text', []];
        yield 'null' => [null, []];
        yield 'empty' => ['', []];
        yield 'symfony style' => ['Hello %name%, you have %count% messages', ['count', 'name']];
        yield 'interpolation style' => ['Hello {{ name }}', ['name']];
        yield 'icu argument' => ['Hello {name}', ['name']];
        yield 'icu plural' => ['{count, plural, one {# item} other {# items}}', ['count']];
        yield 'dotted name' => ['%user.name%', ['user.name']];
        yield 'repeated' => ['%a% and %a%', ['a']];

        // The point of normalising to the argument name: an editor who rewrites a Symfony-style
        // string as ICU has not broken anything, and flagging it would make strict mode
        // unusable.
        yield 'mixed notations agree' => ['%count% of {total}', ['count', 'total']];

        // A lone brace in prose is not a placeholder anyone meant.
        yield 'brace in prose' => ['Use { to open a block', []];
        yield 'percentage sign' => ['100% done', []];
    }
}

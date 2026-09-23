<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Machine;

use Eekes\SuluTranslationsBundle\Application\Machine\IcuMessageSplitter;
use PHPUnit\Framework\TestCase;

class IcuMessageSplitterTest extends TestCase
{
    private IcuMessageSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new IcuMessageSplitter();
    }

    public function testItHandsOverTheSentencesAndKeepsTheSyntax(): void
    {
        $segments = $this->splitter->split('{count, plural, one {# item in your cart} other {# items in your cart}}');

        self::assertNotNull($segments);

        // What the engine is shown: sentences, not `one {`.
        self::assertSame(['# item in your cart', '# items in your cart'], $segments['literals']);
    }

    public function testWhatComesBackReassemblesIntoTheSameShape(): void
    {
        $message = '{count, plural, one {# item in your cart} other {# items in your cart}}';
        $segments = $this->splitter->split($message);

        self::assertNotNull($segments);

        $joined = $this->splitter->join($segments['template'], ['# artikel in uw winkelmandje', '# artikelen in uw winkelmandje']);

        self::assertSame(
            '{count, plural, one {# artikel in uw winkelmandje} other {# artikelen in uw winkelmandje}}',
            $joined,
        );
    }

    public function testTheOriginalSurvivesARoundTripUntouched(): void
    {
        // The strongest thing this class has to promise: whatever it takes apart, it puts
        // back. A single space lost between `one` and `{` is a message that no longer parses.
        $messages = [
            '{count, plural, one {# item} other {# items}}',
            '{count, plural, offset:1 =0 {nobody} one {one person} other {# people}}',
            'Cart: {count, plural, one {# item} other {# items}} - thanks, {name}!',
            '{gender, select, female {She replied} male {He replied} other {They replied}}',
            '{count, plural, one {{name} wrote a comment} other {{name} wrote # comments}}',
            "{count, plural,\n    one {# item}\n    other {# items}\n}",
        ];

        foreach ($messages as $message) {
            $segments = $this->splitter->split($message);

            self::assertNotNull($segments, \sprintf('"%s" was not taken apart.', $message));
            self::assertSame(
                $message,
                $this->splitter->join($segments['template'], $segments['literals']),
                \sprintf('"%s" did not survive the round trip.', $message),
            );
        }
    }

    public function testAnArgumentIsSyntaxAndAFormatIsTooMuch(): void
    {
        $segments = $this->splitter->split('{count, plural, one {{name} owes {amount, number, currency}} other {nothing}}');

        self::assertNotNull($segments);

        // Neither `{name}` nor `{amount, number, currency}` is handed to the engine - one is
        // an argument, the other a format, and the words *between* them are the only prose
        // there is. Sending " owes " on its own is not much of a sentence, but it is the one
        // thing in that branch a translator has to change.
        self::assertSame([' owes ', 'nothing'], $segments['literals']);
        self::assertStringContainsString('{name}', $segments['template']);
        self::assertStringContainsString('{amount, number, currency}', $segments['template']);
    }

    public function testAPlainSentenceIsNotItsBusiness(): void
    {
        // No plural, no select: the ordinary path protects `%name%` and sends the sentence.
        self::assertNull($this->splitter->split('Read more about our services'));
        self::assertNull($this->splitter->split('Hello %name%, you have {count} messages'));
    }

    public function testAMessageItCannotPutBackTogetherIsRefused(): void
    {
        // Rather than hand back a template that would reassemble into something else.
        self::assertNull($this->splitter->split('{count, plural, one {# item} other {# items}'));
    }

    public function testAConstructWithNothingToTranslateIsLeftAlone(): void
    {
        // Nothing but arguments in the branches: there is no sentence to send anywhere, and a
        // round trip through an engine could only damage it.
        self::assertNull($this->splitter->split('{count, plural, one {{name}} other {{name}}}'));
    }
}

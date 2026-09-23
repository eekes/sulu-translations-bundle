<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Machine;

/**
 * Takes an ICU message apart into the sentences a translator should see and the syntax it
 * should never be shown.
 *
 * `{count, plural, one {# item in your cart} other {# items in your cart}}` handed to DeepL as
 * one string comes back with the keywords translated, the braces reflowed, or both - and the
 * write path then refuses it, correctly, as a message that cannot be parsed. The editor sees a
 * run stop on a key that was perfectly fine before anybody touched it.
 *
 * So the branch bodies are pulled out and translated **as separate texts**, which is also what
 * an engine wants: "# item in your cart" is a sentence, `one {` is not. The skeleton - the
 * argument name, the keyword, the categories, the braces - is carried through untouched, so
 * whatever comes back reassembles into a message with exactly the structure it started with.
 *
 * The categories stay as the source wrote them, deliberately. `one`/`other` is valid ICU in
 * every language; a target language that has more categories than the source (`few` and `many`
 * in Polish, `many` in French) needs a person to write those branches, and inventing them from
 * a machine translation of a different category would be worse than leaving them out.
 *
 * @internal
 */
class IcuMessageSplitter
{
    /**
     * Never appears in a translation, and never leaves this class: the literals are sent to
     * the engine on their own, the template stays here.
     */
    private const MARKER = "\x00";

    private const CONSTRUCT = '/\{\s*[a-zA-Z0-9_]+\s*,\s*(?:plural|selectordinal|select)\s*,/';

    /**
     * @return array{template: string, literals: list<string>}|null null when the message has
     *                                                              no plural or select in it,
     *                                                              or is one this cannot take
     *                                                              apart and put back together
     */
    public function split(string $message): ?array
    {
        // A message that is only text and simple arguments needs nothing from here - the
        // ordinary path protects `%name%` and `{name}` and sends the sentence as it is.
        if (1 !== \preg_match(self::CONSTRUCT, $message)) {
            return null;
        }

        $literals = [];
        $offset = 0;
        $template = $this->scan($message, $offset, \strlen($message), $literals);

        if (null === $template || [] === $literals) {
            return null;
        }

        return ['template' => $template, 'literals' => $literals];
    }

    /**
     * @param list<string> $literals in the order `split()` produced them
     */
    public function join(string $template, array $literals): string
    {
        foreach ($literals as $index => $literal) {
            $template = \str_replace(self::MARKER . $index . self::MARKER, $literal, $template);
        }

        return $template;
    }

    /**
     * Text up to `$end`, with every construct it contains carried through as syntax and every
     * run of prose replaced by a marker.
     *
     * @param list<string> $literals
     */
    private function scan(string $message, int &$offset, int $end, array &$literals): ?string
    {
        $template = '';
        $text = '';

        while ($offset < $end) {
            if ('{' !== $message[$offset]) {
                $text .= $message[$offset];
                ++$offset;

                continue;
            }

            $template .= $this->take($text, $literals);
            $construct = $this->readConstruct($message, $offset, $literals);

            if (null === $construct) {
                return null;
            }

            $template .= $construct;
        }

        return $template . $this->take($text, $literals);
    }

    /**
     * Turns the prose collected so far into a literal and hands back its marker. Whitespace on
     * its own is not a sentence and stays in the template.
     *
     * @param list<string> $literals
     */
    private function take(string &$text, array &$literals): string
    {
        if ('' === $text) {
            return '';
        }

        $collected = $text;
        $text = '';

        if ('' === \trim($collected)) {
            return $collected;
        }

        $literals[] = $collected;

        return self::MARKER . (\count($literals) - 1) . self::MARKER;
    }

    /**
     * One `{…}`, from the brace the cursor is on to its match.
     *
     * A plural or select is descended into; anything else - a simple `{name}`, a
     * `{price, number, currency}` - is syntax from end to end and is copied over untouched.
     *
     * @param list<string> $literals
     */
    private function readConstruct(string $message, int &$offset, array &$literals): ?string
    {
        $close = $this->matchingBrace($message, $offset);

        if (null === $close) {
            return null;
        }

        $inner = \substr($message, $offset + 1, $close - $offset - 1);

        if (1 !== \preg_match('/^(\s*[a-zA-Z0-9_]+\s*,\s*(?:plural|selectordinal|select)\s*,)(.*)$/s', $inner, $matches)) {
            $verbatim = \substr($message, $offset, $close - $offset + 1);
            $offset = $close + 1;

            return $verbatim;
        }

        $bodyOffset = 0;
        $branches = $this->readBranches($matches[2], $bodyOffset, $literals);

        if (null === $branches) {
            return null;
        }

        $offset = $close + 1;

        return '{' . $matches[1] . $branches . '}';
    }

    /**
     * The `one {…} other {…}` part. The selectors, the whitespace and `offset:1` are syntax;
     * each body is scanned, so a construct nested inside one is handled the same way.
     *
     * @param list<string> $literals
     */
    private function readBranches(string $body, int &$offset, array &$literals): ?string
    {
        $end = \strlen($body);
        $template = '';

        while ($offset < $end) {
            if ('{' !== $body[$offset]) {
                // A selector, a space, an `offset:1`: never anything to translate.
                $template .= $body[$offset];
                ++$offset;

                continue;
            }

            $close = $this->matchingBrace($body, $offset);

            if (null === $close) {
                return null;
            }

            $branchOffset = $offset + 1;
            $branch = $this->scan($body, $branchOffset, $close, $literals);

            if (null === $branch) {
                return null;
            }

            $template .= '{' . $branch . '}';
            $offset = $close + 1;
        }

        return $template;
    }

    /**
     * The `}` that closes the `{` at `$start`, or null for a message whose braces do not
     * balance - which the write path would have refused, but this never assumes.
     */
    private function matchingBrace(string $message, int $start): ?int
    {
        $depth = 0;
        $end = \strlen($message);

        for ($index = $start; $index < $end; ++$index) {
            if ('{' === $message[$index]) {
                ++$depth;
            } elseif ('}' === $message[$index]) {
                --$depth;

                if (0 === $depth) {
                    return $index;
                }
            }
        }

        return null;
    }
}

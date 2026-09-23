<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Validation;

/**
 * Finds the placeholders in a message, in all three notations a Symfony project mixes.
 *
 * | Notation | Example | Where it comes from |
 * |---|---|---|
 * | Symfony | `%name%` | `trans('x', ['%name%' => $n])` |
 * | Interpolation | `{{ name }}` | Twig-style parameters, common in validator messages |
 * | ICU | `{name}`, `{count, plural, ...}` | an ICU message |
 *
 * Two things come out of the same scan, and the difference between them is the whole reason
 * this class has two methods:
 *
 * - **`extract()` returns argument names**, which is what a comparison has to be made of.
 *   `%count%` and `{count}` compare equal: an editor who rewrites a Symfony-style string as
 *   ICU has not broken anything, and flagging that would make `strict` mode unusable.
 * - **`extractTokens()` returns writable tokens**, which is what the admin shows an editor and
 *   what it pastes into their text when they click a chip. `count` is not something anybody
 *   can type into a translation; `%count%` is.
 *
 * The token is canonical per notation rather than copied verbatim, because the raw match is
 * not always a usable string: the head of `{count, plural, one {...} other {...}}` has no
 * self-contained form, and `{count}` is the honest minimum. `{{name}}` and `{{ name }}` come
 * back as one token for the same reason.
 */
class PlaceholderExtractor
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const NOTATIONS = [
        // `{{ name }}` first: the ICU pattern below would otherwise see the inner brace.
        ['/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', '{{ %s }}'],
        ['/%([a-zA-Z0-9_.]+)%/', '%%%s%%'],
        // ICU arguments: `{name}` or the head of `{name, plural, ...}`.
        ['/\{\s*([a-zA-Z0-9_]+)\s*[,}]/', '{%s}'],
    ];

    /**
     * @return list<string> sorted, unique argument names
     */
    public function extract(?string $message): array
    {
        $names = \array_keys($this->scan($message));
        \sort($names);

        return $names;
    }

    /**
     * @return list<string> unique tokens, in the order they appear in the message
     */
    public function extractTokens(?string $message): array
    {
        return \array_values($this->scan($message));
    }

    /**
     * The names of a stored token list, which is what a comparison is made of.
     *
     * @param list<string> $tokens
     *
     * @return list<string> sorted, unique argument names
     */
    public function namesOf(array $tokens): array
    {
        return $this->extract(\implode(' ', $tokens));
    }

    /**
     * @return array<string, string> argument name => token, in the order they appear
     */
    private function scan(?string $message): array
    {
        if (null === $message || '' === $message) {
            return [];
        }

        $remaining = $message;
        $found = [];

        foreach (self::NOTATIONS as [$pattern, $format]) {
            if (\preg_match_all($pattern, $remaining, $matches, \PREG_OFFSET_CAPTURE | \PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $found[$match[0][1]] = [$match[1][0], \sprintf($format, $match[1][0])];
                }
            }

            // Blank out what matched rather than dropping it, so every offset still to be
            // found stays where it is and the tokens come back in the order an editor reads
            // them - the chips sit under the text they belong to.
            $remaining = (string) \preg_replace_callback(
                $pattern,
                static fn (array $match): string => \str_repeat(' ', \strlen($match[0])),
                $remaining,
            );
        }

        \ksort($found);

        $tokens = [];

        foreach ($found as [$name, $token]) {
            // The first notation wins: `%count%` and `{count}` in one message are one
            // argument, and a chip has to show one thing, not two.
            $tokens[$name] ??= $token;
        }

        return $tokens;
    }
}

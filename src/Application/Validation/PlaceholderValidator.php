<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Validation;

use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderMismatchException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderStillInUseException;

/**
 * Refuses a translation whose placeholders do not match the ones the key declares.
 *
 * The failure this prevents is a `ValueError` or a blank spot on a live page, hours after an
 * editor typed a French sentence and went home. Catching it while they are still looking at
 * the field costs them five seconds.
 *
 * **What a key declares is stored on the key** (`TranslationKey::$placeholders`), derived from
 * the source-locale value. Comparing against the sibling row instead, which is what this did
 * first, left two holes: an editor who emptied or broke the source value switched the check
 * off for every other language at once, and the source value itself was never checked against
 * anything. See `architecture` and `write-path`.
 */
class PlaceholderValidator
{
    public const MODE_STRICT = 'strict';
    public const MODE_OFF = 'off';

    public function __construct(
        private readonly PlaceholderExtractor $extractor,
        private readonly string $mode = self::MODE_STRICT,
    ) {
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function isEnabled(): bool
    {
        return self::MODE_OFF !== $this->mode;
    }

    /**
     * Refuse or allow. There is no middle setting: with nothing to flag a value with, a
     * "warn" mode would write a log line nobody reads and let the broken string through.
     *
     * @param list<string>|null $expected the tokens the key declares; null means nothing has
     *                                    declared them yet, which is not a reason to refuse
     *                                    anything
     *
     * @throws PlaceholderMismatchException
     */
    public function validate(?array $expected, ?string $value, string $locale): void
    {
        if (self::MODE_OFF === $this->mode || null === $expected || [] === $expected) {
            return;
        }

        // Clearing a translation is how an editor says "not translated yet", and that must
        // never be refused for lacking placeholders.
        if (null === $value || '' === $value) {
            return;
        }

        $expectedNames = $this->extractor->namesOf($expected);
        $actual = $this->extractor->extract($value);

        $missing = \array_values(\array_diff($expectedNames, $actual));
        $unexpected = \array_values(\array_diff($actual, $expectedNames));

        if ([] === $missing && [] === $unexpected) {
            return;
        }

        throw new PlaceholderMismatchException($missing, $unexpected, $locale);
    }

    /**
     * Whether a value carries everything the key declares.
     *
     * Same comparison as `validate()`, without the exception and without the mode: the admin
     * marks an incomplete row whether or not writes are being refused, because "this sentence
     * lost its name" is worth seeing either way.
     *
     * @param list<string>|null $expected
     *
     * @return list<string> the tokens the value does not carry
     */
    public function findMissing(?array $expected, ?string $value): array
    {
        if (null === $expected || [] === $expected || null === $value || '' === $value) {
            return [];
        }

        $actual = $this->extractor->extract($value);
        $missing = [];

        foreach ($expected as $token) {
            $names = $this->extractor->namesOf([$token]);

            if ([] !== \array_diff($names, $actual)) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /**
     * Guards the moment the declared set changes, which only a source-locale write can do.
     *
     * Removals are refused while another language still carries them, because those values
     * would become unsavable the moment this write lands and nothing would say so. Additions
     * are allowed: they leave the other languages incomplete, which the admin shows and an
     * editor fixes row by row - refusing them instead would deadlock the pair, since no
     * translation may carry an argument the source has not got.
     *
     * @param list<string> $current the tokens the key declares today
     * @param list<string> $next the tokens the new source value declares
     * @param array<string, string> $values the other languages' values, keyed by locale
     *
     * @throws PlaceholderStillInUseException
     */
    public function assertRedefinable(array $current, array $next, array $values): void
    {
        if (self::MODE_OFF === $this->mode) {
            return;
        }

        $removed = \array_values(\array_diff(
            $this->extractor->namesOf($current),
            $this->extractor->namesOf($next),
        ));

        if ([] === $removed) {
            return;
        }

        $locales = [];
        $inUse = [];

        foreach ($values as $locale => $value) {
            $used = \array_intersect($removed, $this->extractor->extract($value));

            if ([] === $used) {
                continue;
            }

            $locales[] = (string) $locale;
            $inUse = \array_merge($inUse, $used);
        }

        if ([] === $locales) {
            return;
        }

        // Report the tokens as they were declared, not the bare argument names: what an
        // editor has to go and delete is `%name%`, not `name`.
        $inUse = \array_values(\array_unique($inUse));
        $tokens = \array_values(\array_filter(
            $current,
            fn (string $token): bool => [] !== \array_intersect($this->extractor->namesOf([$token]), $inUse),
        ));

        throw new PlaceholderStillInUseException($tokens, $locales);
    }
}

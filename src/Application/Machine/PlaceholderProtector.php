<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Machine;

/**
 * Hides placeholders from the translation engine and puts them back afterwards.
 *
 * Every engine mangles `%name%` sooner or later - translating the word inside it, moving the
 * percent signs, or helpfully capitalising it. Replacing them with an opaque tag before the
 * call is the documented remedy for DeepL (`tag_handling=xml`) and works as well for the
 * others, because an unknown XML tag is something every engine is careful with.
 */
class PlaceholderProtector
{
    private const PATTERN = '/(\{\{\s*[a-zA-Z0-9_.]+\s*\}\}|%[a-zA-Z0-9_.]+%|\{[a-zA-Z0-9_]+\})/';

    /**
     * @return array{0: string, 1: array<string, string>} the protected text and the tag map
     */
    public function protect(string $text): array
    {
        $map = [];
        $index = 0;

        $protected = \preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use (&$map, &$index): string {
                $tag = \sprintf('<x id="%d"/>', $index);
                $map[$tag] = $matches[0];
                ++$index;

                return $tag;
            },
            $text,
        );

        return [$protected ?? $text, $map];
    }

    /**
     * Engines reformat the tag they were given - `<x id="0"></x>`, single quotes, an extra
     * space - so the tag is matched with a pattern rather than compared literally.
     *
     * @param array<string, string> $map
     *
     * @return string|null null when a placeholder did not survive the round trip - the caller
     *                     discards that result rather than storing a broken string
     */
    public function restore(string $text, array $map): ?string
    {
        foreach (\array_values($map) as $index => $original) {
            $pattern = \sprintf('/<x\s+id=["\']?%d["\']?\s*(?:\/>|>\s*<\/x>)/', $index);
            // A callback rather than a replacement string: `$` and `\` in a translation value
            // would otherwise be read as backreferences.
            $replaced = \preg_replace_callback($pattern, static fn (): string => $original, $text, 1, $count);

            if (null === $replaced || 0 === $count) {
                return null;
            }

            $text = $replaced;
        }

        return $text;
    }
}

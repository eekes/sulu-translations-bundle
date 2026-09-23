<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;

/**
 * Everything the admin shows ships in all four languages. A key added to one catalogue and
 * forgotten in the others does not fail anywhere - Symfony falls back and the editor reads
 * English in a Dutch admin, which nobody reports as a bug and nobody fixes either.
 */
class AdminCatalogueTest extends TestCase
{
    private const LOCALES = ['en', 'nl', 'de', 'fr'];

    public function testEveryCatalogueHasTheSameKeys(): void
    {
        $reference = \array_keys($this->catalogue('en'));
        \sort($reference);

        foreach (self::LOCALES as $locale) {
            $keys = \array_keys($this->catalogue($locale));
            \sort($keys);

            self::assertSame(
                $reference,
                $keys,
                \sprintf('The "%s" catalogue does not have the same keys as the English one.', $locale),
            );
        }
    }

    public function testNoValueIsLeftEmptyOrUntranslated(): void
    {
        $english = $this->catalogue('en');

        foreach (self::LOCALES as $locale) {
            foreach ($this->catalogue($locale) as $key => $value) {
                self::assertNotSame('', \trim($value), \sprintf('"%s" is empty in "%s".', $key, $locale));

                // A copy of the English is how a forgotten key hides from the test above.
                // Rare words are the same in two languages, so this only looks at sentences.
                if ('en' === $locale || \substr_count($value, ' ') < 3) {
                    continue;
                }

                self::assertNotSame(
                    $english[$key],
                    $value,
                    \sprintf('"%s" is still the English sentence in "%s".', $key, $locale),
                );
            }
        }
    }

    public function testEveryParameterIsWrittenTheWayTheAdminSubstitutesIt(): void
    {
        // Sulu's admin translator is `IntlMessageFormat`, so a parameter is `{name}`.
        // Symfony's `%name%` is not substituted, it is not an error either, and it reaches the
        // screen verbatim: the globe's tooltip read "Translate from %locale%" for exactly as
        // long as nobody looked at it. The same shape was in two confirmation dialogs.
        foreach (self::LOCALES as $locale) {
            foreach ($this->catalogue($locale) as $key => $value) {
                self::assertDoesNotMatchRegularExpression(
                    '/%[a-zA-Z_]+%/',
                    $value,
                    \sprintf(
                        '"%s" in "%s" uses a Symfony placeholder. The admin renders ICU, so write {name}.',
                        $key,
                        $locale,
                    ),
                );
            }
        }
    }

    public function testEveryParameterExistsInAllFourLanguages(): void
    {
        // A sentence that drops a parameter in one language is not a fallback, it is a hole:
        // "{count} translations will change" becomes "translations will change".
        $english = $this->catalogue('en');

        foreach (self::LOCALES as $locale) {
            foreach ($this->catalogue($locale) as $key => $value) {
                self::assertSame(
                    $this->parameters($english[$key]),
                    $this->parameters($value),
                    \sprintf('"%s" does not use the same parameters in "%s" as in English.', $key, $locale),
                );
            }
        }
    }

    /**
     * The `{name}` parameters of a message, sorted. Plural and select blocks are ICU syntax
     * rather than parameters of their own, and none of our catalogue uses them yet.
     *
     * @return list<string>
     */
    private function parameters(string $message): array
    {
        \preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $message, $matches);

        $parameters = \array_values(\array_unique($matches[1]));
        \sort($parameters);

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(string $locale): array
    {
        $path = \dirname(__DIR__, 3) . '/translations/admin.' . $locale . '.json';

        self::assertFileExists($path);

        /** @var array<string, string> $catalogue */
        $catalogue = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);

        return $catalogue;
    }
}

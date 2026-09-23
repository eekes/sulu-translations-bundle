<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

/**
 * The source-locale value dropped a placeholder that another language is still using.
 *
 * The source value is what defines the placeholders of a key, so editing it redefines them -
 * and shrinking the set turns every translation that still carries the removed argument into
 * a value the write path refuses. There is no status to mark those rows with (see
 * `write-path`), so the only honest moment to say something is now, to the person making the
 * change, naming the languages they have to deal with first.
 *
 * Adding a placeholder is not this. That leaves the other languages *incomplete*, which the
 * admin can show and an editor can fix one row at a time; refusing it as well would deadlock
 * the pair, because no translation may carry an argument the source has not got yet.
 */
final class PlaceholderStillInUseException extends \DomainException implements TranslationExceptionInterface
{
    /**
     * @param list<string> $placeholders the tokens being removed that are still in use
     * @param list<string> $locales the languages still using them
     */
    public function __construct(
        private readonly array $placeholders,
        private readonly array $locales,
    ) {
        parent::__construct(\sprintf(
            'The placeholder%s %s %s still used by %s. Remove %s from those translations first.',
            1 === \count($placeholders) ? '' : 's',
            \implode(', ', $placeholders),
            1 === \count($placeholders) ? 'is' : 'are',
            \implode(', ', $locales),
            1 === \count($placeholders) ? 'it' : 'them',
        ));
    }

    /**
     * @return list<string>
     */
    public function getPlaceholders(): array
    {
        return $this->placeholders;
    }

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        return $this->locales;
    }
}

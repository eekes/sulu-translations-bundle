<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

/**
 * A translation whose placeholders do not match the source locale.
 *
 * Carries the two lists rather than only a message, because the admin renders them as a
 * sentence in the editor's own language - see `write-path`.
 */
final class PlaceholderMismatchException extends \DomainException implements TranslationExceptionInterface
{
    /**
     * @param list<string> $missing present in the source, absent from the translation
     * @param list<string> $unexpected present in the translation, absent from the source
     */
    public function __construct(
        private readonly array $missing,
        private readonly array $unexpected,
        private readonly string $locale,
    ) {
        parent::__construct(\sprintf(
            'The translation for locale "%s" does not use the same placeholders as the source.%s%s',
            $locale,
            [] === $missing ? '' : \sprintf(' Missing: %s.', \implode(', ', $missing)),
            [] === $unexpected ? '' : \sprintf(' Unknown: %s.', \implode(', ', $unexpected)),
        ));
    }

    /**
     * @return list<string>
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * @return list<string>
     */
    public function getUnexpected(): array
    {
        return $this->unexpected;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}

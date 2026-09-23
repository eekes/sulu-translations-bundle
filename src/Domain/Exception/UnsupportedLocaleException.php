<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

final class UnsupportedLocaleException extends \DomainException implements TranslationExceptionInterface
{
    /**
     * @param list<string> $available
     */
    public function __construct(string $locale, array $available)
    {
        parent::__construct(\sprintf(
            'The locale "%s" is not one this project translates. Available: %s.',
            $locale,
            [] === $available ? '(none)' : \implode(', ', $available),
        ));
    }
}

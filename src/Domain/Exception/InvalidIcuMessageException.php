<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

final class InvalidIcuMessageException extends \DomainException implements TranslationExceptionInterface
{
    public function __construct(
        private readonly string $reason,
        private readonly string $locale,
    ) {
        parent::__construct(\sprintf('The ICU message for locale "%s" cannot be parsed: %s', $locale, $reason));
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

/**
 * A write the server refuses regardless of what the UI offered: a read-only domain, a locale
 * the user has no permission for, or a delete of a protected key.
 */
final class TranslationWriteNotAllowedException extends \DomainException implements TranslationExceptionInterface
{
    public static function readOnlyDomain(string $domain): self
    {
        return new self(\sprintf('The translation domain "%s" is configured as read-only.', $domain));
    }

    public static function protectedKey(string $key): self
    {
        return new self(\sprintf('The translation key "%s" is protected and cannot be deleted.', $key));
    }

    public static function locale(string $locale): self
    {
        return new self(\sprintf('You are not allowed to edit translations in locale "%s".', $locale));
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

final class TranslationNotFoundException extends \RuntimeException implements TranslationExceptionInterface
{
    public static function forId(int $id): self
    {
        return new self(\sprintf('There is no translation with id "%d".', $id));
    }

    public static function forKeyAndLocale(string $key, string $domain, string $locale): self
    {
        return new self(\sprintf('There is no translation of key "%s" (domain "%s") in locale "%s".', $key, $domain, $locale));
    }
}

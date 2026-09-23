<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

final class TranslationKeyNotFoundException extends \RuntimeException implements TranslationExceptionInterface
{
    public static function forId(int $id): self
    {
        return new self(\sprintf('There is no translation key with id "%d".', $id));
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Exception;

final class MachineTranslationException extends \RuntimeException implements TranslationExceptionInterface
{
    public static function unsupportedPair(string $source, string $target): self
    {
        return new self(\sprintf('The configured machine translator cannot translate from "%s" to "%s".', $source, $target));
    }

    public static function providerFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The machine translation provider failed: %s', $reason), 0, $previous);
    }

    public static function countMismatch(int $sent, int $received): self
    {
        return new self(\sprintf('The machine translation provider returned %d results for %d texts.', $received, $sent));
    }
}

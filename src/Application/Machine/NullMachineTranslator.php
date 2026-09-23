<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Machine;

/**
 * What is wired when machine translation is not configured. It supports nothing, so the admin
 * hides the action and the command exits with a readable message instead of a type error.
 */
final class NullMachineTranslator implements MachineTranslatorInterface
{
    public function translate(array $texts, string $sourceLocale, string $targetLocale): array
    {
        return [];
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return false;
    }
}

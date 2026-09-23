<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Machine;

use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;

/**
 * Four lines, on purpose.
 *
 * Every project has its own opinion about which engine, which model and who pays for it, and
 * an interface this small is one afternoon to implement against anything - DeepL, Claude,
 * Google, an internal service. What the bundle refuses to own is a prompt.
 */
interface MachineTranslatorInterface
{
    /**
     * Batched because every provider charges and rate-limits per request: a two thousand key
     * run one HTTP call at a time is an hour.
     *
     * @param list<string> $texts
     *
     * @throws MachineTranslationException
     *
     * @return list<string> one result per input, in the same order
     */
    public function translate(array $texts, string $sourceLocale, string $targetLocale): array;

    public function supports(string $sourceLocale, string $targetLocale): bool;
}

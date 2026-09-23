<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Manager;

use Eekes\SuluTranslationsBundle\Domain\Event\AbstractTranslationBatchEvent;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationModifiedEvent;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;

/**
 * The write API of the bundle. Public: a consuming project that fills translations from its own
 * importer is expected to use this rather than the repositories, so validation, versions,
 * activity and cache invalidation still happen.
 */
interface TranslationManagerInterface
{
    public function saveValue(Translation $translation, ?string $value): Translation;

    /**
     * Applies a write without flushing; returns the event to collect, or null when nothing
     * changed. Pair it with `commit()`.
     *
     * @param bool $allowPlaceholderRedefinition lets a source-locale value drop a placeholder
     *                                           another language is still using. For the
     *                                           importer: translation files are the
     *                                           developer's own, and a deploy may not fail
     *                                           over a string an editor typed. See
     *                                           `write-path`.
     */
    public function stageValue(
        Translation $translation,
        ?string $value,
        bool $allowPlaceholderRedefinition = false,
    ): ?TranslationModifiedEvent;

    public function commit(?AbstractTranslationBatchEvent $batchEvent = null): void;

    /**
     * @return bool whether the flag actually changed
     */
    public function setIcu(TranslationKey $key, bool $icu): bool;

    public function restoreVersion(TranslationVersion $version): Translation;

    public function deleteKey(TranslationKey $key): void;

    public function searchAndReplace(
        string $locale,
        string $search,
        string $replace,
        ?string $domain = null,
        bool $dryRun = false,
    ): int;

    public function copyLocale(
        string $fromLocale,
        string $toLocale,
        ?string $domain = null,
        bool $overwrite = false,
        bool $dryRun = false,
    ): int;
}

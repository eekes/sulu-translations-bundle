<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Repository;

use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;

interface TranslationRepositoryInterface
{
    public function findById(int $id): ?Translation;

    public function findOneByKeyAndLocale(TranslationKey $key, string $locale): ?Translation;

    /**
     * @param list<int> $ids
     *
     * @return list<Translation>
     */
    public function findByIds(array $ids): array;

    public function save(Translation $translation, bool $flush = true): void;

    /**
     * Every non-empty value of one locale, shaped the way a `MessageCatalogue` wants it.
     *
     * Deliberately a DBAL query and not an ORM one: this runs on the website, on every cache
     * miss, for the whole catalogue. Hydrating thousands of entities to read two columns is
     * the kind of thing that makes people distrust database-backed translations.
     *
     * @return array<string, array<string, string>> catalogue domain (with `+intl-icu` where it
     *                                              applies) => key => value
     */
    public function loadCatalogue(string $locale): array;

    /**
     * Every non-empty value, with what the migration generator needs to decide whether a
     * release still has to carry it.
     *
     * `shipped` is `migrated_at IS NOT NULL` - a generated migration has taken this value to
     * the other environments. `changedSinceShipped` is `changed > migrated_at` - somebody has
     * edited it since, so shipping it again would overwrite whatever production has.
     *
     * A DBAL query for the same reason `loadCatalogue()` is one: this reads the whole
     * catalogue and only ever writes SQL out of it.
     *
     * @param list<string> $locales empty means every locale that has rows
     *
     * @return list<array{domain: string, key: string, hash: string, icu: bool, locale: string, value: string, shipped: bool, changedSinceShipped: bool}>
     */
    public function findForMigration(array $locales = [], ?string $domain = null): array;

    /**
     * Creates the missing `(key, locale)` rows for the given locales, in one statement per
     * locale, and returns how many it created. Idempotent by construction.
     *
     * @param list<string> $locales
     */
    public function materialiseLocales(array $locales): int;

    /**
     * Values that are empty in `$targetLocale` while the same key has content in
     * `$sourceLocale` - the input of a machine-translation run and of "copy from locale".
     *
     * @param list<int> $onlyKeyIds restricts the run to a selection, empty means no restriction
     * @param bool $onlyEmptyTarget false also returns the pairs whose target already has a
     *                              value - what "copy from locale, overwriting" needs
     *
     * @return list<array{id: int, keyId: int, key: string, domain: string, icu: bool, source: string}>
     */
    public function findUntranslated(string $sourceLocale, string $targetLocale, ?string $domain = null, array $onlyKeyIds = [], ?int $limit = null, bool $onlyEmptyTarget = true): array;

    /**
     * @return list<Translation>
     */
    public function findForSearchAndReplace(string $locale, string $search, ?string $domain = null): array;

    /**
     * @return list<string> the locales that already have rows
     */
    public function findLocales(): array;

    /**
     * The value of a set of keys in one locale, keyed by key id - what the reference-locale
     * column of the list needs, in one query for the whole page.
     *
     * @param list<int> $keyIds
     *
     * @return array<int, string|null>
     */
    public function findValuesByKeyIds(array $keyIds, string $locale): array;
}

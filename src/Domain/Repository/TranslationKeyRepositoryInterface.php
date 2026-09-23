<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Repository;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;

interface TranslationKeyRepositoryInterface
{
    public function findById(int $id): ?TranslationKey;

    public function findOneByKey(string $domain, string $key): ?TranslationKey;

    /**
     * @param list<int> $ids
     *
     * @return list<TranslationKey>
     */
    public function findByIds(array $ids): array;

    /**
     * The keys `eekes:translations:sync-placeholders` has to look at: everything that has
     * never declared its placeholders, or everything at all when the command is asked to
     * derive them again from scratch.
     *
     * @return list<TranslationKey>
     */
    public function findForPlaceholderSync(bool $onlyUndeclared = true): array;

    public function save(TranslationKey $key, bool $flush = true): void;

    public function remove(TranslationKey $key, bool $flush = true): void;

    /**
     * @return list<string> every domain that has at least one key
     */
    public function findDomains(): array;

    /**
     * Keys that the extractor did not find and that nothing has asked for since `$usedSince`.
     *
     * Both signals are needed: static extraction misses dynamically built keys, and runtime
     * usage misses pages nobody visited during the window.
     *
     * @param list<string> $seenKeyHashes hashes the extractor did find, per domain: "domain\0hash"
     *
     * @return list<TranslationKey>
     */
    public function findUnused(array $seenKeyHashes, \DateTimeImmutable $usedSince): array;

    /**
     * @return list<TranslationKey>
     */
    public function findOrphanedBefore(\DateTimeImmutable $orphanedBefore, bool $includeProtected = false): array;

    /**
     * Bumps `lastUsedAt` for the given keys without loading them, skipping the ones that were
     * already touched after `$threshold`. Called once per request from the runtime recorder,
     * so it has to be one statement.
     *
     * @param list<string> $keyHashesByDomain "domain\0hash" pairs
     */
    public function touchLastUsed(array $keyHashesByDomain, \DateTimeImmutable $threshold): void;

    /**
     * How many of one locale's values have content. The other half of the answer is
     * `countAll()`, and together they are the whole progress report.
     */
    public function countTranslated(string $locale): int;

    public function countAll(): int;
}

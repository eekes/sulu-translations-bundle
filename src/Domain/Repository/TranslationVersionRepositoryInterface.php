<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Repository;

use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;

interface TranslationVersionRepositoryInterface
{
    public function findById(int $id): ?TranslationVersion;

    /**
     * @return list<TranslationVersion>
     */
    public function findByTranslation(Translation $translation, int $limit = 50, int $offset = 0): array;

    public function countByTranslation(Translation $translation): int;

    /**
     * Drops everything beyond the newest `$keep` versions of one translation.
     */
    public function prune(Translation $translation, int $keep): int;
}

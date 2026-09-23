<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Repository;

interface CatalogueStateRepositoryInterface
{
    /**
     * The token and counter of the current catalogue, as `<token>.<version>`, or an empty
     * string when nothing has ever been written.
     *
     * Implementations must not create the row on a read: a website request that only reads
     * must never write.
     */
    public function getFingerprint(): string;

    /**
     * Increments the counter, creating the row if this is the first write, and returns the new
     * fingerprint. Called inside the write transaction, last. See the `write-path` skill.
     */
    public function bump(): string;
}

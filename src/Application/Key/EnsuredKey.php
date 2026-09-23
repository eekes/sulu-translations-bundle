<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Key;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;

/**
 * "The key, and whether we had to make it." An importer reports created and updated counts
 * separately, and after a flush the entity itself can no longer tell you which it was.
 */
final class EnsuredKey
{
    public function __construct(
        public readonly TranslationKey $key,
        public readonly bool $created,
    ) {
    }
}

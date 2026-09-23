<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

/**
 * Search and replace, copy from another locale, bulk approve.
 */
class TranslationsBulkModifiedEvent extends AbstractTranslationBatchEvent
{
    public function getEventType(): string
    {
        return 'bulk_modified';
    }
}

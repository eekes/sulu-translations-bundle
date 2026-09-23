<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

class TranslationsImportedEvent extends AbstractTranslationBatchEvent
{
    public function getEventType(): string
    {
        return 'imported';
    }
}

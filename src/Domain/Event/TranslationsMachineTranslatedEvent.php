<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

class TranslationsMachineTranslatedEvent extends AbstractTranslationBatchEvent
{
    public function getEventType(): string
    {
        return 'machine_translated';
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

/**
 * Deleting a key a template still renders is safe - it reappears with source `runtime` on the
 * next request - which is exactly why the trail has to record that someone did it.
 */
class TranslationKeyRemovedEvent extends AbstractTranslationEvent
{
    public function getEventType(): string
    {
        return 'removed';
    }
}

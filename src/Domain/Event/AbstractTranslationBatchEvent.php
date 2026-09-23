<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

/**
 * One event for a whole run - an import, an export, a machine-translation pass, a bulk replace.
 *
 * Deliberately not one event per row: an import of 3 000 keys that writes 3 000 activity rows
 * makes the activity trail useless for everyone else in the system. The counts are in the
 * payload instead.
 */
abstract class AbstractTranslationBatchEvent extends DomainEvent
{
    /**
     * @param array<string, scalar|null> $summary
     */
    public function __construct(
        private readonly array $summary,
        private readonly ?string $locale = null,
    ) {
        parent::__construct();
    }

    public function getResourceKey(): string
    {
        return TranslationKey::RESOURCE_KEY;
    }

    /**
     * There is no single resource; the trail shows these at collection level.
     */
    public function getResourceId(): string
    {
        return '0';
    }

    public function getResourceLocale(): ?string
    {
        return $this->locale;
    }

    public function getResourceSecurityContext(): ?string
    {
        return TranslationKey::SECURITY_CONTEXT;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getEventPayload(): ?array
    {
        return $this->summary;
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

/**
 * Base for everything the activity trail shows about translations.
 *
 * The resource is always the **key**, never the per-locale value: a trail with one entry per
 * locale of the same string reads like noise, and the locale is what `resourceLocale` is for.
 * The title is the key itself, because that is what an editor searched for to get here.
 */
abstract class AbstractTranslationEvent extends DomainEvent
{
    public function __construct(
        private readonly int $keyId,
        private readonly string $translationKey,
        private readonly string $domain,
        private readonly ?string $locale = null,
    ) {
        parent::__construct();
    }

    public function getTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getResourceKey(): string
    {
        return TranslationKey::RESOURCE_KEY;
    }

    public function getResourceId(): string
    {
        return (string) $this->keyId;
    }

    public function getResourceTitle(): ?string
    {
        return $this->translationKey;
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
     * @return array{domain: string}
     */
    public function getEventContext(): array
    {
        return ['domain' => $this->domain];
    }
}

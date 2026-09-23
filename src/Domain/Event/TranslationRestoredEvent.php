<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

class TranslationRestoredEvent extends AbstractTranslationEvent
{
    public function __construct(
        int $keyId,
        string $translationKey,
        string $domain,
        string $locale,
        private readonly int $versionId,
        private readonly ?string $value,
    ) {
        parent::__construct($keyId, $translationKey, $domain, $locale);
    }

    public function getEventType(): string
    {
        return 'restored';
    }

    /**
     * @return array{version: int, value: string|null}
     */
    public function getEventPayload(): ?array
    {
        return ['version' => $this->versionId, 'value' => $this->value];
    }
}

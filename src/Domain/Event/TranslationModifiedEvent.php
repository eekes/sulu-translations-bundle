<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

/**
 * A value changed. Dispatched only when it actually changed: the inline editor and the form
 * both send a PUT on blur, and a trail full of no-op entries is worse than no trail.
 *
 * The payload carries both values so the activity list can show the change without a join, and
 * so the history survives the translation row being deleted.
 */
class TranslationModifiedEvent extends AbstractTranslationEvent
{
    public function __construct(
        int $keyId,
        string $translationKey,
        string $domain,
        string $locale,
        private readonly ?string $from,
        private readonly ?string $to,
    ) {
        parent::__construct($keyId, $translationKey, $domain, $locale);
    }

    public function getEventType(): string
    {
        return 'modified';
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    public function getEventPayload(): ?array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}

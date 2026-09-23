<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Domain\Event;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;

/**
 * A key appeared - typed in the admin, imported, extracted, or asked for at runtime by a
 * `trans()` call nothing could answer. The source is in the payload because "where did this
 * come from" is the first question anyone asks about an unfamiliar key.
 */
class TranslationKeyCreatedEvent extends AbstractTranslationEvent
{
    public function __construct(
        int $keyId,
        string $translationKey,
        string $domain,
        private readonly TranslationSource $source,
    ) {
        parent::__construct($keyId, $translationKey, $domain);
    }

    public function getEventType(): string
    {
        return 'created';
    }

    /**
     * @return array{source: string}
     */
    public function getEventPayload(): ?array
    {
        return ['source' => $this->source->value];
    }
}

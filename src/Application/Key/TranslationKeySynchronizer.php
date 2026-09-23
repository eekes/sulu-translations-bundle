<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Key;

use Doctrine\ORM\EntityManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationKeyCreatedEvent;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;

/**
 * Creates keys and keeps a value row per configured locale behind every one of them.
 *
 * That materialisation is what makes the admin list work at all - see the `architecture`
 * skill. Everything that can discover a key goes through here: the importer, the extractor,
 * the runtime recorder and the admin's own add form.
 */
class TranslationKeySynchronizer
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?DomainEventCollectorInterface $domainEventCollector = null,
    ) {
    }

    /**
     * Finds a key or creates it, with an empty value row for every locale.
     *
     * @param bool $collectEvent false for bulk discovery - three hundred "key created" entries
     *                           from one page render make the activity trail unusable, so those
     *                           callers collect one batch event instead
     */
    public function ensureKey(
        string $domain,
        string $key,
        TranslationSource $source = TranslationSource::Manual,
        bool $icu = false,
        bool $flush = true,
        bool $collectEvent = true,
    ): TranslationKey {
        return $this->ensure($domain, $key, $source, $icu, $flush, $collectEvent)->key;
    }

    /**
     * The same thing, but it also tells you whether the key had to be created - which the
     * entity itself can no longer answer once it has been flushed.
     */
    public function ensure(
        string $domain,
        string $key,
        TranslationSource $source = TranslationSource::Manual,
        bool $icu = false,
        bool $flush = true,
        bool $collectEvent = true,
    ): EnsuredKey {
        $existing = $this->keyRepository->findOneByKey($domain, $key);

        if (null !== $existing) {
            $this->fillLocales($existing);

            if ($flush) {
                $this->entityManager->flush();
            }

            return new EnsuredKey($existing, false);
        }

        $translationKey = new TranslationKey($domain, $key, $source);
        $translationKey->setIcu($icu);

        $this->fillLocales($translationKey);
        $this->keyRepository->save($translationKey, $flush);

        if ($collectEvent && null !== $translationKey->getId()) {
            $this->domainEventCollector?->collect(new TranslationKeyCreatedEvent(
                $translationKey->getId(),
                $translationKey->getKey(),
                $translationKey->getDomain(),
                $source,
            ));
        }

        return new EnsuredKey($translationKey, true);
    }

    /**
     * Adds the rows a newly configured locale needs across every existing key, and the rows
     * every key needs for the locales it is missing. Idempotent, and cheap enough to call
     * before a list request.
     */
    public function synchroniseLocales(): int
    {
        return $this->translationRepository->materialiseLocales($this->localeProvider->getLocales());
    }

    /**
     * For callers that created keys with `flush: false` and want one round trip for the lot.
     */
    public function flush(): void
    {
        $this->entityManager->flush();
    }

    private function fillLocales(TranslationKey $translationKey): void
    {
        foreach ($this->localeProvider->getLocales() as $locale) {
            if (null === $translationKey->getTranslation($locale)) {
                $translation = new Translation($translationKey, $locale);
                $this->entityManager->persist($translation);
            }
        }
    }
}

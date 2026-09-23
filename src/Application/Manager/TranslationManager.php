<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Application\Validation\IcuMessageValidator;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderExtractor;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderValidator;
use Eekes\SuluTranslationsBundle\Domain\Event\AbstractTranslationBatchEvent;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationKeyRemovedEvent;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationModifiedEvent;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationRestoredEvent;
use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderStillInUseException;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationVersionRepositoryInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The only thing allowed to write a translation value.
 *
 * Six things have to happen together on every write and in this order - authorise, validate,
 * version, persist, record, bump - and scattering them across controllers and commands is how
 * a bundle ends up with a cache that is stale on one code path out of five. See the
 * `write-path` skill.
 *
 * `stageValue()` plus `commit()` is the batch form: an import or a machine-translation run
 * stages thousands of rows, then commits once, with one activity event and one version bump.
 */
class TranslationManager implements TranslationManagerInterface
{
    /**
     * @param list<string> $readOnlyDomains
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly TranslationVersionRepositoryInterface $versionRepository,
        private readonly CatalogueStateRepositoryInterface $stateRepository,
        private readonly PlaceholderValidator $placeholderValidator,
        private readonly PlaceholderExtractor $placeholderExtractor,
        private readonly IcuMessageValidator $icuValidator,
        private readonly CatalogueProvider $catalogueProvider,
        private readonly CatalogueVersionProvider $versionProvider,
        private readonly string $sourceLocale,
        private readonly array $readOnlyDomains = [],
        private readonly int $versionsKeep = 20,
        private readonly ?DomainEventCollectorInterface $domainEventCollector = null,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function saveValue(Translation $translation, ?string $value): Translation
    {
        $event = $this->stageValue($translation, $value);

        if (null !== $event) {
            $this->domainEventCollector?->collect($event);
        }

        $this->commit();

        return $translation;
    }

    /**
     * Applies a write without flushing. Returns the event the caller should collect, or null
     * when nothing actually changed - the inline editor and the form both send a PUT on blur,
     * and a trail full of no-op entries is worse than no trail.
     */
    public function stageValue(
        Translation $translation,
        ?string $value,
        bool $allowPlaceholderRedefinition = false,
    ): ?TranslationModifiedEvent {
        $key = $translation->getKey();
        $locale = $translation->getLocale();

        $this->assertWritable($key);

        $value = ('' === $value) ? null : $value;
        $previousValue = $translation->getValue();

        if ($locale === $this->sourceLocale) {
            // The source value does not get validated against the placeholders - it *is* what
            // declares them. What it does get is the one rule that keeps the other languages
            // savable: it may not drop an argument they are still using.
            $this->declarePlaceholders($key, $value, $allowPlaceholderRedefinition);
        } elseif (null !== $value) {
            $this->placeholderValidator->validate($this->expectedPlaceholders($key), $value, $locale);
        }

        if (null !== $value && $key->isIcu()) {
            $this->icuValidator->validate($value, $locale);
        }

        if ($previousValue === $value) {
            return null;
        }

        // The version row holds what it used to say, so the newest version is the value before
        // the edit that is happening now.
        $version = new TranslationVersion(
            $translation,
            $previousValue,
            $this->getUserId(),
            $this->getUserName(),
        );
        $this->entityManager->persist($version);

        $translation->setValue($value);
        $this->translationRepository->save($translation, false);

        $this->versionRepository->prune($translation, $this->versionsKeep);

        return new TranslationModifiedEvent(
            (int) $key->getId(),
            $key->getKey(),
            $key->getDomain(),
            $locale,
            $previousValue,
            $value,
        );
    }

    /**
     * Turns the ICU flag of a key on or off.
     *
     * Not a plain setter on the entity: the flag decides which catalogue domain every value
     * of this key lands in (`messages` vs `messages+intl-icu`) and therefore which formatter
     * renders it. Switching it on with a value the intl parser chokes on would turn every
     * page that renders it into an exception, so **every** language is validated first, not
     * just the one the editor happens to be looking at.
     *
     * @throws InvalidIcuMessageException naming the language that is in the way
     */
    public function setIcu(TranslationKey $key, bool $icu): bool
    {
        if ($key->isIcu() === $icu) {
            return false;
        }

        $this->assertWritable($key);

        if ($icu) {
            foreach ($key->getTranslations() as $translation) {
                $this->icuValidator->validate($translation->getValue(), $translation->getLocale());
            }
        }

        $key->setIcu($icu);
        $this->keyRepository->save($key, false);

        // The runtime domain changed, so every cached catalogue is stale even though not a
        // single value was touched.
        $this->commit();

        return true;
    }

    public function restoreVersion(TranslationVersion $version): Translation
    {
        $translation = $version->getTranslation();

        $this->assertWritable($translation->getKey());

        $event = $this->stageValue($translation, $version->getValue());

        if (null !== $event) {
            $key = $translation->getKey();

            $this->domainEventCollector?->collect(new TranslationRestoredEvent(
                (int) $key->getId(),
                $key->getKey(),
                $key->getDomain(),
                $translation->getLocale(),
                (int) $version->getId(),
                $version->getValue(),
            ));
        }

        $this->commit();

        return $translation;
    }

    public function deleteKey(TranslationKey $key): void
    {
        if ($key->isProtected()) {
            throw TranslationWriteNotAllowedException::protectedKey($key->getKey());
        }

        $this->assertWritable($key);

        $this->domainEventCollector?->collect(new TranslationKeyRemovedEvent(
            (int) $key->getId(),
            $key->getKey(),
            $key->getDomain(),
        ));

        $this->keyRepository->remove($key, false);

        $this->commit();
    }

    /**
     * Flush, bump the catalogue version, and drop the in-process caches so the rest of this
     * request already sees the write.
     *
     * The bump is last and inside the transaction: bumping first lets a concurrent reader
     * cache the *old* messages under the *new* version, which is a stale string nobody can
     * explain and nothing invalidates.
     */
    public function commit(?AbstractTranslationBatchEvent $batchEvent = null): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->flush();
            $this->stateRepository->bump();
        });

        if (null !== $batchEvent) {
            $this->domainEventCollector?->collect($batchEvent);
            // A batch event has no entity write of its own to ride along with.
            $this->entityManager->flush();
        }

        $this->versionProvider->reset();
        $this->catalogueProvider->reset();
    }

    public function searchAndReplace(
        string $locale,
        string $search,
        string $replace,
        ?string $domain = null,
        bool $dryRun = false,
    ): int {
        if ('' === $search) {
            return 0;
        }

        $matches = $this->translationRepository->findForSearchAndReplace($locale, $search, $domain);

        if ($dryRun) {
            return \count($matches);
        }

        $changed = 0;

        foreach ($matches as $translation) {
            $value = $translation->getValue();

            if (null === $value) {
                continue;
            }

            if (null !== $this->stageValue($translation, \str_replace($search, $replace, $value))) {
                ++$changed;
            }
        }

        return $changed;
    }

    public function copyLocale(
        string $fromLocale,
        string $toLocale,
        ?string $domain = null,
        bool $overwrite = false,
        bool $dryRun = false,
    ): int {
        $candidates = $this->translationRepository->findUntranslated(
            $fromLocale,
            $toLocale,
            $domain,
            onlyEmptyTarget: !$overwrite,
        );

        if ($dryRun) {
            return \count($candidates);
        }

        $changed = 0;

        foreach ($candidates as $row) {
            $translation = $this->translationRepository->findById($row['id']);

            if (null === $translation) {
                continue;
            }

            if (null !== $this->stageValue($translation, $row['source'])) {
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Writes the placeholders a source-locale value declares onto the key.
     *
     * Only a non-empty value declares anything. Clearing the English text says "nobody has
     * translated this yet", not "this string takes no arguments", and letting it wipe the set
     * would switch the check off for every other language at once - which is exactly the hole
     * deriving the set on every read used to leave open.
     *
     * @throws PlaceholderStillInUseException when the new value drops an argument another
     *                                        language is still carrying
     */
    private function declarePlaceholders(TranslationKey $key, ?string $value, bool $allowRedefinition): void
    {
        if (null === $value) {
            return;
        }

        $next = $this->placeholderExtractor->extractTokens($value);
        $current = $key->getPlaceholders();

        if ($current === $next) {
            return;
        }

        // A key that has never declared anything is being filled in, not redefined - and an
        // importer is the developer's own files landing, which are the authority here and
        // must never be able to fail a deploy over a string an editor typed.
        if (null !== $current && !$allowRedefinition) {
            $this->placeholderValidator->assertRedefinable($current, $next, $this->translatedValues($key));
        }

        $key->setPlaceholders($next);
        $this->keyRepository->save($key, false);
    }

    /**
     * What the key declares, or - for a row from before the column existed - what its source
     * value says right now. The fallback is what keeps an install that has not run
     * `eekes:translations:sync-placeholders` yet from silently losing the check.
     *
     * @return list<string>
     */
    private function expectedPlaceholders(TranslationKey $key): array
    {
        $declared = $key->getPlaceholders();

        if (null !== $declared) {
            return $declared;
        }

        return $this->placeholderExtractor->extractTokens($this->resolveSourceValue($key));
    }

    /**
     * Every language that has something in it, except the source itself.
     *
     * @return array<string, string>
     */
    private function translatedValues(TranslationKey $key): array
    {
        $values = [];

        foreach ($key->getTranslations() as $translation) {
            $value = $translation->getValue();

            if ($translation->getLocale() === $this->sourceLocale || null === $value || '' === $value) {
                continue;
            }

            $values[$translation->getLocale()] = $value;
        }

        return $values;
    }

    private function resolveSourceValue(TranslationKey $key): ?string
    {
        return $key->getTranslation($this->sourceLocale)?->getValue()
            ?? $this->translationRepository->findOneByKeyAndLocale($key, $this->sourceLocale)?->getValue();
    }

    private function assertWritable(TranslationKey $key): void
    {
        if (\in_array($key->getDomain(), $this->readOnlyDomains, true)) {
            throw TranslationWriteNotAllowedException::readOnlyDomain($key->getDomain());
        }
    }

    private function getUser(): ?UserInterface
    {
        $user = $this->tokenStorage?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    private function getUserId(): ?int
    {
        $id = $this->getUser()?->getId();

        return \is_int($id) ? $id : null;
    }

    private function getUserName(): ?string
    {
        // `getUserIdentifier()`, not `getUsername()`: the latter is gone from Symfony's
        // UserInterface, and what the history should show is what the login screen asks for.
        return $this->getUser()?->getUserIdentifier();
    }
}

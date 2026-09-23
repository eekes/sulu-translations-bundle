<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationsImportedEvent;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;

/**
 * Reads translation files into the database.
 *
 * The rule that makes this safe to run on every deploy: **a value that already has content in
 * the database is never overwritten** unless the caller asks for it. New keys from the release
 * appear with their file values, and everything an editor changed since the last deploy
 * survives. Without that rule the bundle would lose a week of editing on every release, which
 * is precisely what `translation:pull` does.
 */
class FileImporter
{
    private const SAMPLE_LIMIT = 50;

    public function __construct(
        private readonly TranslationReaderInterface $reader,
        private readonly CatalogueDomainSplitter $splitter,
        private readonly TranslationKeySynchronizer $synchronizer,
        private readonly TranslationManagerInterface $manager,
        private readonly LocaleProvider $localeProvider,
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly ManagedDomains $domains = new ManagedDomains(),
    ) {
    }

    /**
     * @param list<string> $paths directories holding translation files
     * @param list<string>|null $locales null means every locale this project has
     */
    public function preview(array $paths, ?array $locales = null, ?string $domain = null, bool $overwrite = false): ImportResult
    {
        return $this->run($paths, $locales, $domain, $overwrite, true, false);
    }

    /**
     * @param list<string> $paths
     * @param list<string>|null $locales
     */
    public function import(
        array $paths,
        ?array $locales = null,
        ?string $domain = null,
        bool $overwrite = false,
        bool $protect = false,
    ): ImportResult {
        return $this->run($paths, $locales, $domain, $overwrite, false, $protect);
    }

    /**
     * @param list<string> $paths
     * @param list<string>|null $locales
     */
    private function run(
        array $paths,
        ?array $locales,
        ?string $domain,
        bool $overwrite,
        bool $dryRun,
        bool $protect,
    ): ImportResult {
        $result = (new ImportResult())->asDryRun($dryRun);
        $locales ??= $this->localeProvider->getLocales();

        foreach ($locales as $locale) {
            $catalogue = new MessageCatalogue($locale);

            foreach ($paths as $path) {
                if (!\is_dir($path)) {
                    continue;
                }

                $this->reader->read($path, $catalogue);
            }

            $result = $this->importCatalogue($catalogue, $locale, $domain, $overwrite, $dryRun, $protect, $result);
        }

        if (!$dryRun && $result->getTouched() + $result->keysCreated > 0) {
            $this->manager->commit(new TranslationsImportedEvent($result->toArray()));
        }

        return $result;
    }

    private function importCatalogue(
        MessageCatalogue $catalogue,
        string $locale,
        ?string $domain,
        bool $overwrite,
        bool $dryRun,
        bool $protect,
        ImportResult $result,
    ): ImportResult {
        foreach ($this->splitter->split($catalogue) as $group) {
            if (null !== $domain) {
                if ($group['domain'] !== $domain) {
                    continue;
                }
            } elseif ($this->domains->isIgnored($group['domain'])) {
                // `%kernel.project_dir%/translations` is read recursively, so a project that
                // keeps `translations/sulu/admin.nl.json` to rename a handful of Sulu's own
                // labels would otherwise import the whole admin catalogue. Naming the domain
                // with --domain is how somebody says they meant it.
                $result = $result->with(domainsSkipped: [$group['domain']]);

                continue;
            }

            foreach ($group['messages'] as $key => $value) {
                $result = $this->importMessage(
                    $group['domain'],
                    (string) $key,
                    (string) $value,
                    $locale,
                    $group['icu'],
                    $overwrite,
                    $dryRun,
                    $protect,
                    $result,
                );
            }
        }

        return $result;
    }

    private function importMessage(
        string $domain,
        string $key,
        string $value,
        string $locale,
        bool $icu,
        bool $overwrite,
        bool $dryRun,
        bool $protect,
        ImportResult $result,
    ): ImportResult {
        if ($dryRun) {
            // A preview may not touch the unit of work: the whole point is to show what an
            // import *would* do without any chance of it happening.
            $existing = $this->keyRepository->findOneByKey($domain, $key);
            $current = $existing?->getTranslation($locale)?->getValue();
            $isNewKey = null === $existing;
            $translation = null;
        } else {
            $ensured = $this->synchronizer->ensure(
                $domain,
                $key,
                TranslationSource::File,
                $icu,
                flush: true,
                collectEvent: false,
            );

            $isNewKey = $ensured->created;
            $translationKey = $ensured->key;
            $translation = $translationKey->getTranslation($locale);

            if (null === $translation) {
                return $result->with(valuesSkipped: 1);
            }

            $current = $translation->getValue();
        }

        if ($current === $value) {
            return $result->with(
                keysCreated: $isNewKey ? 1 : 0,
                valuesUnchanged: 1,
            );
        }

        if (null !== $current && '' !== $current && !$overwrite) {
            // Somebody edited this in the admin. That is the version that wins.
            return $result->with(keysCreated: $isNewKey ? 1 : 0, valuesSkipped: 1);
        }

        $sample = \count($result->samples) < self::SAMPLE_LIMIT
            ? [[
                'domain' => $domain,
                'key' => $key,
                'locale' => $locale,
                'from' => $current,
                'to' => $value,
                'type' => null === $current || '' === $current ? 'added' : 'changed',
            ]]
            : [];

        if ($dryRun) {
            return $result->with(
                keysCreated: $isNewKey ? 1 : 0,
                valuesCreated: null === $current || '' === $current ? 1 : 0,
                valuesUpdated: null === $current || '' === $current ? 0 : 1,
                samples: $sample,
            );
        }

        if ($protect) {
            $translationKey->setProtected(true);
        }

        if (null !== $translation) {
            // `allowPlaceholderRedefinition`: a translation file is the developer's own, and
            // the arguments a `trans()` call passes are decided there. If English dropped
            // `%name%` since the last release, the import has to land - a deploy that fails
            // because an editor's French still carries the old placeholder is a deploy nobody
            // can finish. The rows that now mismatch show up in the admin.
            $this->manager->stageValue($translation, $value, allowPlaceholderRedefinition: true);
        }

        return $result->with(
            keysCreated: $isNewKey ? 1 : 0,
            valuesCreated: null === $current || '' === $current ? 1 : 0,
            valuesUpdated: null === $current || '' === $current ? 0 : 1,
            samples: $sample,
        );
    }
}

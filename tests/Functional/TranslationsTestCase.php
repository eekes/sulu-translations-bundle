<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Doctrine\ORM\Tools\SchemaTool;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\MessageCatalogueEnricher;
use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationVersionRepositoryInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

abstract class TranslationsTestCase extends SuluTestCase
{
    private static bool $schemaCreated = false;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createAuthenticatedClient();

        if (!self::$schemaCreated) {
            self::createSchema();
            self::$schemaCreated = true;
        }

        static::purgeDatabase();
    }

    protected static function createSchema(): void
    {
        $entityManager = static::getEntityManager();
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected static function createConsoleApplication(): Application
    {
        return new Application(static::$kernel ?? static::bootKernel());
    }

    protected static function getTranslationManager(): TranslationManagerInterface
    {
        /** @var TranslationManagerInterface $manager */
        $manager = static::getContainer()->get(TranslationManagerInterface::class);

        return $manager;
    }

    protected static function getSynchronizer(): TranslationKeySynchronizer
    {
        /** @var TranslationKeySynchronizer $synchronizer */
        $synchronizer = static::getContainer()->get(TranslationKeySynchronizer::class);

        return $synchronizer;
    }

    protected static function getKeyRepository(): TranslationKeyRepositoryInterface
    {
        /** @var TranslationKeyRepositoryInterface $repository */
        $repository = static::getContainer()->get(TranslationKeyRepositoryInterface::class);

        return $repository;
    }

    protected static function getTranslationRepository(): TranslationRepositoryInterface
    {
        /** @var TranslationRepositoryInterface $repository */
        $repository = static::getContainer()->get(TranslationRepositoryInterface::class);

        return $repository;
    }

    protected static function getVersionRepository(): TranslationVersionRepositoryInterface
    {
        /** @var TranslationVersionRepositoryInterface $repository */
        $repository = static::getContainer()->get(TranslationVersionRepositoryInterface::class);

        return $repository;
    }

    protected static function getDomainEventCollector(): DomainEventCollectorInterface
    {
        /** @var DomainEventCollectorInterface $collector */
        $collector = static::getContainer()->get('sulu_activity.domain_event_collector');

        return $collector;
    }

    /**
     * What `kernel.reset` does between two requests. A test that translates, writes and
     * translates again inside one process has to say so out loud rather than rely on it.
     */
    protected static function resetCatalogueCaches(): void
    {
        /** @var CatalogueVersionProvider $versionProvider */
        $versionProvider = static::getContainer()->get('eekes_sulu_translations.catalogue_version');
        /** @var CatalogueProvider $catalogueProvider */
        $catalogueProvider = static::getContainer()->get('eekes_sulu_translations.catalogue_provider');
        /** @var MessageCatalogueEnricher $enricher */
        $enricher = static::getContainer()->get('eekes_sulu_translations.catalogue_enricher');

        $versionProvider->reset();
        $catalogueProvider->reset();
        $enricher->reset();
    }

    /**
     * Creates a key with a value in one locale, through the manager - so a fixture goes down
     * exactly the road a real save does, versions and version bump included.
     */
    protected static function givenTranslation(
        string $key,
        string $locale,
        ?string $value,
        string $domain = 'messages',
        bool $icu = false,
    ): Translation {
        $translationKey = static::getSynchronizer()->ensureKey($domain, $key, TranslationSource::Manual, $icu);
        $translation = $translationKey->getTranslation($locale);

        self::assertNotNull($translation, \sprintf('No row was materialised for locale "%s".', $locale));

        if (null !== $value) {
            static::getTranslationManager()->saveValue($translation, $value);
        }

        return $translation;
    }

    protected static function findKey(string $key, string $domain = 'messages'): ?TranslationKey
    {
        return static::getKeyRepository()->findOneByKey($domain, $key);
    }

    /**
     * The same lookup for the many assertions that only make sense if the key is there.
     */
    protected static function requireKey(string $key, string $domain = 'messages'): TranslationKey
    {
        $translationKey = static::findKey($key, $domain);

        self::assertNotNull($translationKey, \sprintf('There is no key "%s" in domain "%s".', $key, $domain));

        return $translationKey;
    }

    protected static function valueOf(string $key, string $locale, string $domain = 'messages'): ?string
    {
        return static::requireKey($key, $domain)->getTranslation($locale)?->getValue();
    }
}

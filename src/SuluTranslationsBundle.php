<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle;

use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Catalogue\MessageCatalogueEnricher;
use Eekes\SuluTranslationsBundle\Application\Controller\Admin\TranslationActionController;
use Eekes\SuluTranslationsBundle\Application\Controller\Admin\TranslationController;
use Eekes\SuluTranslationsBundle\Application\Controller\Admin\TranslationVersionController;
use Eekes\SuluTranslationsBundle\Application\Io\CatalogueDomainSplitter;
use Eekes\SuluTranslationsBundle\Application\Io\CatalogueExporter;
use Eekes\SuluTranslationsBundle\Application\Io\CodeScanner;
use Eekes\SuluTranslationsBundle\Application\Io\FileImporter;
use Eekes\SuluTranslationsBundle\Application\Io\MigrationGenerator;
use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Machine\IcuMessageSplitter;
use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslationRunner;
use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslatorInterface;
use Eekes\SuluTranslationsBundle\Application\Machine\NullMachineTranslator;
use Eekes\SuluTranslationsBundle\Application\Machine\PlaceholderProtector;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManager;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Recorder\MissingMessageRecorder;
use Eekes\SuluTranslationsBundle\Application\Validation\IcuMessageValidator;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderExtractor;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderValidator;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationVersionRepositoryInterface;
use Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository\CatalogueStateRepository;
use Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository\TranslationKeyRepository;
use Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository\TranslationRepository;
use Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository\TranslationVersionRepository;
use Eekes\SuluTranslationsBundle\Infrastructure\HttpClient\DeeplMachineTranslator;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\Metadata\AdminFieldsMetadataVisitor;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Admin\TranslationsAdmin;
use Eekes\SuluTranslationsBundle\Infrastructure\Sulu\Security\TranslationSecurityChecker;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\DumpCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\ExportCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\GenerateMigrationCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\ImportCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\PruneCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\ScanCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\StatusCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\SyncLocalesCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\SyncPlaceholdersCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\TranslateCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command\UnusedCommand;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\EventSubscriber\RecordDiscoveredKeysSubscriber;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Translation\DatabaseTranslator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @codeCoverageIgnore
 */
final class SuluTranslationsBundle extends AbstractBundle
{
    public const ALIAS = 'eekes_sulu_translations';

    /**
     * Set by hand, because `AbstractBundle` derives the alias from the class name and would
     * make this `sulu_translations` - a namespace that belongs to Sulu (`sulu_admin`,
     * `sulu_page`, `sulu_activity`) and that any other bundle called `SuluTranslationsBundle`
     * claims as well. The vendor prefix is what keeps a project's `config/packages` from
     * having two bundles arguing over one key.
     */
    protected string $extensionAlias = self::ALIAS;

    /**
     * @internal this method is not part of the public API and should only be called by Symfony
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        // Everything has a default that fits a stock Sulu project. A consumer that wants the
        // bundle's core behaviour writes no YAML at all.
        $definition->rootNode()
            ->children()
                ->scalarNode('source_locale')
                    ->defaultNull()
                    ->info('The locale translators translate *from*, and the one placeholders are validated against. Defaults to the application default_locale.')
                ->end()
                ->arrayNode('extra_locales')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Locales to manage on top of the ones the webspaces declare - transactional mail, an app API, a PDF.')
                ->end()
                ->arrayNode('domains')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('read_only')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Domains nobody may write through the admin. `validators` and `admin` are the usual candidates.')
                        ->end()
                        ->arrayNode('ignore')
                            ->scalarPrototype()->end()
                            ->defaultValue(['admin'])
                            ->info('Catalogues the bundle never fills by itself: not by discovery, not by import, scan or generate-migration --from=files. `admin` is Sulu\'s own. Adding a key there by hand still works, and `--domain=admin` still imports it.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('pool')
                            ->defaultValue('cache.app')
                            ->info('The pool the compiled catalogue is stored in. A shared pool (Redis) means both Sulu kernels reuse one copy.')
                        ->end()
                        ->integerNode('version_ttl')
                            ->defaultValue(0)
                            ->min(0)
                            ->info('Seconds to reuse the catalogue version without asking the database. 0 means an edit is live on the next request.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('validation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('placeholders')
                            ->values([PlaceholderValidator::MODE_STRICT, PlaceholderValidator::MODE_OFF])
                            ->defaultValue(PlaceholderValidator::MODE_STRICT)
                            ->info('Refuse a translation that does not use the same placeholders as the source, or allow it.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('versions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('keep')
                            ->defaultValue(20)
                            ->min(0)
                            ->info('How many previous values to keep per translation.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('discovery')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('collect_missing')
                            ->values(['auto', 'always', 'never'])
                            ->defaultValue('auto')
                            ->info('Record keys nothing could answer. "auto" means on when kernel.debug is on; "always" is supported and is how a project finds the strings its templates ask for.')
                        ->end()
                        ->integerNode('limit')
                            ->defaultValue(200)
                            ->min(1)
                            ->info('Maximum number of keys recorded per request, so a loop over a broken key cannot write a million rows.')
                        ->end()
                        ->booleanNode('track_usage')
                            ->defaultTrue()
                            ->info('Refresh lastUsedAt on the keys that did resolve, independently of collect_missing. This is what fills "Last shown" and what makes the unused-keys report trustworthy.')
                        ->end()
                        ->integerNode('usage_throttle')
                            ->defaultValue(86400)
                            ->min(60)
                            ->info('Seconds before lastUsedAt is written again for the same key.')
                        ->end()
                        ->arrayNode('scan_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['%kernel.project_dir%/templates', '%kernel.project_dir%/src'])
                            ->info('What `eekes:translations:scan` extracts from.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('import')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['%kernel.project_dir%/translations'])
                            ->info('Directories `eekes:translations:import` reads by default.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('migrations')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')
                            ->defaultValue('%kernel.project_dir%/migrations')
                            ->info('Where `generate-migration` writes. The project\'s own migrations directory, so its deploy already runs it.')
                        ->end()
                        ->scalarNode('namespace')
                            ->defaultValue('DoctrineMigrations')
                            ->info('Namespace of the generated class. Must match doctrine_migrations.migrations_paths.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('%kernel.project_dir%/translations')->end()
                        ->scalarNode('format')->defaultValue('yaml')->end()
                    ->end()
                ->end()
                ->arrayNode('dump')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')
                            ->defaultValue('%kernel.project_dir%/var/translations')
                            ->info('Where the IDE snapshot is written. Registered with the translator as a low-priority path in debug mode.')
                        ->end()
                        ->scalarNode('format')->defaultValue('yaml')->end()
                    ->end()
                ->end()
                ->arrayNode('permissions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('per_locale')
                            ->defaultFalse()
                            ->info('Check permissions per locale, using the locale restriction Sulu roles already have. Off by default: it doubles the permission matrix, and most teams edit everything.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('machine_translation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('service')
                            ->defaultNull()
                            ->info('A service id implementing MachineTranslatorInterface. Set this to use anything other than DeepL.')
                        ->end()
                        ->integerNode('batch_size')->defaultValue(50)->min(1)->end()
                        ->integerNode('max_per_run')->defaultValue(500)->min(1)->end()
                        ->arrayNode('deepl')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('api_key')->defaultNull()->end()
                                ->scalarNode('formality')->defaultNull()->info('default, more, less, prefer_more or prefer_less.')->end()
                                ->scalarNode('glossary_id')->defaultNull()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal this method is not part of the public API and should only be called by Symfony
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        /** @var array{source_locale: string|null, extra_locales: list<string>, domains: array{read_only: list<string>, ignore: list<string>}, cache: array{pool: string, version_ttl: int}, validation: array{placeholders: string}, versions: array{keep: int}, discovery: array{collect_missing: string, limit: int, track_usage: bool, usage_throttle: int, scan_paths: list<string>}, import: array{paths: list<string>}, migrations: array{directory: string, namespace: string}, export: array{path: string, format: string}, dump: array{path: string, format: string}, permissions: array{per_locale: bool}, machine_translation: array{enabled: bool, service: string|null, batch_size: int, max_per_run: int, deepl: array{api_key: string|null, formality: string|null, glossary_id: string|null}}} $config */
        $sourceLocale = $config['source_locale'] ?? '%kernel.default_locale%';

        $builder->setParameter('eekes_sulu_translations.source_locale', $sourceLocale);
        $builder->setParameter('eekes_sulu_translations.machine_translation.enabled', $config['machine_translation']['enabled']);

        // --- Persistence -----------------------------------------------------------------

        $services->set('eekes_sulu_translations.key_repository', TranslationKeyRepository::class)
            ->args([service('doctrine.orm.entity_manager')]);
        $services->alias(TranslationKeyRepositoryInterface::class, 'eekes_sulu_translations.key_repository')->public();

        $services->set('eekes_sulu_translations.translation_repository', TranslationRepository::class)
            ->args([service('doctrine.orm.entity_manager')]);
        $services->alias(TranslationRepositoryInterface::class, 'eekes_sulu_translations.translation_repository')->public();

        $services->set('eekes_sulu_translations.version_repository', TranslationVersionRepository::class)
            ->args([service('doctrine.orm.entity_manager')]);
        $services->alias(TranslationVersionRepositoryInterface::class, 'eekes_sulu_translations.version_repository');

        $services->set('eekes_sulu_translations.state_repository', CatalogueStateRepository::class)
            ->args([service('doctrine.orm.entity_manager')]);
        $services->alias(CatalogueStateRepositoryInterface::class, 'eekes_sulu_translations.state_repository');

        // --- Runtime catalogue -----------------------------------------------------------

        $services->set('eekes_sulu_translations.catalogue_version', CatalogueVersionProvider::class)
            ->args([
                service('eekes_sulu_translations.state_repository'),
                $config['cache']['version_ttl'],
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'translations'])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set('eekes_sulu_translations.catalogue_provider', CatalogueProvider::class)
            ->args([
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.catalogue_version'),
                service($config['cache']['pool'])->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'translations'])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set('eekes_sulu_translations.catalogue_enricher', MessageCatalogueEnricher::class)
            ->args([
                service('eekes_sulu_translations.catalogue_provider'),
                service('eekes_sulu_translations.catalogue_version'),
            ])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set('eekes_sulu_translations.managed_domains', ManagedDomains::class)
            ->args([$config['domains']['ignore']]);

        $services->set('eekes_sulu_translations.recorder', MissingMessageRecorder::class)
            ->args([
                service('eekes_sulu_translations.key_synchronizer'),
                service('eekes_sulu_translations.key_repository'),
                $this->shouldCollectMissing($config['discovery']['collect_missing'], $builder),
                $config['discovery']['limit'],
                $config['discovery']['usage_throttle'],
                $config['discovery']['track_usage'],
                service('sulu_activity.domain_event_collector')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
                service('eekes_sulu_translations.managed_domains'),
            ])
            ->tag('monolog.logger', ['channel' => 'translations'])
            ->tag('kernel.reset', ['method' => 'reset']);

        $services->set('eekes_sulu_translations.record_subscriber', RecordDiscoveredKeysSubscriber::class)
            ->args([service('eekes_sulu_translations.recorder')])
            ->tag('kernel.event_subscriber');

        // Priority 10 puts us inside translator.data_collector and translator.logging, so the
        // profiler and the logs show what the site actually served.
        $services->set('eekes_sulu_translations.translator', DatabaseTranslator::class)
            ->decorate('translator', null, 10)
            ->args([
                service('.inner'),
                service('eekes_sulu_translations.catalogue_enricher'),
                service('eekes_sulu_translations.recorder'),
            ]);

        // --- Application -----------------------------------------------------------------

        $services->set('eekes_sulu_translations.locale_provider', LocaleProvider::class)
            ->args([
                service('sulu_core.webspace.webspace_manager'),
                $config['extra_locales'],
                param('kernel.default_locale'),
            ])
            ->tag('kernel.reset', ['method' => 'reset'])
            ->public();
        $services->alias(LocaleProvider::class, 'eekes_sulu_translations.locale_provider')->public();

        $services->set('eekes_sulu_translations.key_synchronizer', TranslationKeySynchronizer::class)
            ->args([
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.locale_provider'),
                service('doctrine.orm.entity_manager'),
                service('sulu_activity.domain_event_collector')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(TranslationKeySynchronizer::class, 'eekes_sulu_translations.key_synchronizer')->public();

        $services->set('eekes_sulu_translations.placeholder_extractor', PlaceholderExtractor::class);

        $services->set('eekes_sulu_translations.placeholder_validator', PlaceholderValidator::class)
            ->args([
                service('eekes_sulu_translations.placeholder_extractor'),
                $config['validation']['placeholders'],
            ]);

        $services->set('eekes_sulu_translations.icu_validator', IcuMessageValidator::class);

        $services->set('eekes_sulu_translations.manager', TranslationManager::class)
            ->args([
                service('doctrine.orm.entity_manager'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.version_repository'),
                service('eekes_sulu_translations.state_repository'),
                service('eekes_sulu_translations.placeholder_validator'),
                service('eekes_sulu_translations.placeholder_extractor'),
                service('eekes_sulu_translations.icu_validator'),
                service('eekes_sulu_translations.catalogue_provider'),
                service('eekes_sulu_translations.catalogue_version'),
                $sourceLocale,
                $config['domains']['read_only'],
                $config['versions']['keep'],
                service('sulu_activity.domain_event_collector')->nullOnInvalid(),
                service('security.token_storage')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(TranslationManagerInterface::class, 'eekes_sulu_translations.manager')->public();

        // --- Import, export, scanning -----------------------------------------------------

        $services->set('eekes_sulu_translations.catalogue_domain_splitter', CatalogueDomainSplitter::class);

        $services->set('eekes_sulu_translations.importer', FileImporter::class)
            ->args([
                service('translation.reader'),
                service('eekes_sulu_translations.catalogue_domain_splitter'),
                service('eekes_sulu_translations.key_synchronizer'),
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.managed_domains'),
            ])
            ->public();
        $services->alias(FileImporter::class, 'eekes_sulu_translations.importer')->public();

        $services->set('eekes_sulu_translations.exporter', CatalogueExporter::class)
            ->args([
                service('translation.writer'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.filesystem'),
                service('eekes_sulu_translations.manager'),
                $config['export']['path'],
                $config['export']['format'],
            ])
            ->public();
        $services->alias(CatalogueExporter::class, 'eekes_sulu_translations.exporter')->public();

        $services->set('eekes_sulu_translations.filesystem', \Symfony\Component\Filesystem\Filesystem::class);

        // Translations -> SQL. From the files, as the on-ramp for a project that already has
        // them, and from the database ever after, so the keys a developer filled in locally
        // travel with the release instead of waiting for somebody to retype them on
        // production.
        $services->set('eekes_sulu_translations.migration_generator', MigrationGenerator::class)
            ->args([
                service('translation.reader'),
                service('eekes_sulu_translations.catalogue_domain_splitter'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.filesystem'),
                service('eekes_sulu_translations.translation_repository'),
                $config['import']['paths'],
                $config['migrations']['directory'],
                $config['migrations']['namespace'],
                service('eekes_sulu_translations.managed_domains'),
            ])
            ->public();
        $services->alias(MigrationGenerator::class, 'eekes_sulu_translations.migration_generator')->public();

        $services->set('eekes_sulu_translations.scanner', CodeScanner::class)
            ->args([
                service('translation.extractor'),
                service('eekes_sulu_translations.key_synchronizer'),
                service('eekes_sulu_translations.key_repository'),
                $config['discovery']['scan_paths'],
                param('kernel.default_locale'),
                param('kernel.project_dir'),
                service('eekes_sulu_translations.managed_domains'),
            ]);

        // --- Machine translation ----------------------------------------------------------

        $this->registerMachineTranslation($config, $services, $builder);

        // --- Admin -------------------------------------------------------------------------

        $services->set('eekes_sulu_translations.security_checker', TranslationSecurityChecker::class)
            ->args([
                service('sulu_security.security_checker'),
                $config['permissions']['per_locale'],
            ]);

        $services->set('eekes_sulu_translations.admin', TranslationsAdmin::class)
            ->args([
                service('sulu_admin.view_builder_factory'),
                service('sulu_security.security_checker'),
                service('eekes_sulu_translations.locale_provider'),
                service('sulu_activity.activity_list_view_builder_factory')->nullOnInvalid(),
                $config['machine_translation']['enabled'],
            ])
            ->tag('sulu.context', ['context' => 'admin'])
            ->tag('sulu.admin');

        // Removes the context panel from the form and its columns from the list for users
        // without the context permission. One service, both visitor interfaces.
        $services->set('eekes_sulu_translations.admin_fields_metadata_visitor', AdminFieldsMetadataVisitor::class)
            ->args([service('eekes_sulu_translations.security_checker')])
            ->tag('sulu_admin.form_metadata_visitor')
            ->tag('sulu_admin.list_metadata_visitor');

        $services->set('eekes_sulu_translations.admin_controller', TranslationController::class)
            ->public()
            ->args([
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.key_synchronizer'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.security_checker'),
                service('sulu_core.list_builder.field_descriptor_factory'),
                service('sulu_core.doctrine_list_builder_factory'),
                service('sulu_core.rest_helper'),
                $sourceLocale,
            ])
            ->tag('sulu.context', ['context' => 'admin']);

        $services->set('eekes_sulu_translations.version_controller', TranslationVersionController::class)
            ->public()
            ->args([
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.version_repository'),
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.security_checker'),
            ])
            ->tag('sulu.context', ['context' => 'admin']);

        $services->set('eekes_sulu_translations.action_controller', TranslationActionController::class)
            ->public()
            ->args([
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.locale_provider'),
                service('eekes_sulu_translations.security_checker'),
                service('eekes_sulu_translations.importer'),
                service('eekes_sulu_translations.machine_translation_runner'),
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.filesystem'),
                $sourceLocale,
                $config['machine_translation']['enabled'],
            ])
            ->tag('sulu.context', ['context' => 'admin']);

        // --- Commands -------------------------------------------------------------------------

        $services->set('eekes_sulu_translations.command.import', ImportCommand::class)
            ->args([service('eekes_sulu_translations.importer'), $config['import']['paths']])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.export', ExportCommand::class)
            ->args([service('eekes_sulu_translations.exporter')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.generate_migration', GenerateMigrationCommand::class)
            ->args([service('eekes_sulu_translations.migration_generator')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.scan', ScanCommand::class)
            ->args([service('eekes_sulu_translations.scanner')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.translate', TranslateCommand::class)
            ->args([service('eekes_sulu_translations.machine_translation_runner'), $sourceLocale])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.status', StatusCommand::class)
            ->args([service('eekes_sulu_translations.key_repository'), service('eekes_sulu_translations.locale_provider')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.sync_locales', SyncLocalesCommand::class)
            ->args([service('eekes_sulu_translations.key_synchronizer'), service('eekes_sulu_translations.locale_provider')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.sync_placeholders', SyncPlaceholdersCommand::class)
            ->args([
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.placeholder_extractor'),
                service('eekes_sulu_translations.placeholder_validator'),
                service('doctrine.orm.entity_manager'),
                $sourceLocale,
            ])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.prune', PruneCommand::class)
            ->args([
                service('eekes_sulu_translations.key_repository'),
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.migration_generator'),
            ])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.unused', UnusedCommand::class)
            ->args([service('eekes_sulu_translations.key_repository')])
            ->tag('console.command');

        $services->set('eekes_sulu_translations.command.dump', DumpCommand::class)
            ->args([service('eekes_sulu_translations.exporter'), $config['dump']['path'], $config['dump']['format']])
            ->tag('console.command');
    }

    /**
     * @internal this method is not part of the public API and should only be called by Symfony
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'SuluTranslations' => [
                            'type' => 'attribute',
                            'prefix' => 'Eekes\\SuluTranslationsBundle\\Domain\\Model',
                            'dir' => $this->getPath() . '/src/Domain/Model',
                            'alias' => 'SuluTranslations',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('sulu_admin')) {
            $builder->prependExtensionConfig('sulu_admin', [
                'lists' => [
                    'directories' => [$this->getPath() . '/config/lists'],
                ],
                'forms' => [
                    'directories' => [$this->getPath() . '/config/forms'],
                ],
                'resources' => [
                    TranslationKey::RESOURCE_KEY => [
                        'routes' => [
                            'list' => 'eekes_sulu_translations.get_translation_keys',
                            'detail' => 'eekes_sulu_translations.get_translation_key',
                        ],
                    ],
                    TranslationVersion::RESOURCE_KEY => [
                        'routes' => [
                            'list' => 'eekes_sulu_translations.get_translation_versions',
                            'detail' => 'eekes_sulu_translations.post_translation_version_trigger',
                        ],
                    ],
                ],
            ]);
        }

        if ($builder->hasExtension('framework')) {
            $builder->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [$this->getPath() . '/translations'],
                ],
            ]);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMachineTranslation(array $config, \Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator $services, ContainerBuilder $builder): void
    {
        /** @var array{enabled: bool, service: string|null, batch_size: int, max_per_run: int, deepl: array{api_key: string|null, formality: string|null, glossary_id: string|null}} $machine */
        $machine = $config['machine_translation'];

        $services->set('eekes_sulu_translations.placeholder_protector', PlaceholderProtector::class);
        $services->set('eekes_sulu_translations.icu_message_splitter', IcuMessageSplitter::class);

        if (!$machine['enabled']) {
            $services->set('eekes_sulu_translations.machine_translator', NullMachineTranslator::class);
        } elseif (null !== $machine['service']) {
            $services->alias('eekes_sulu_translations.machine_translator', $machine['service']);
        } else {
            $services->set('eekes_sulu_translations.machine_translator', DeeplMachineTranslator::class)
                ->args([
                    service('http_client'),
                    (string) ($machine['deepl']['api_key'] ?? ''),
                    [],
                    $machine['deepl']['formality'],
                    $machine['deepl']['glossary_id'],
                ]);
        }

        $services->alias(MachineTranslatorInterface::class, 'eekes_sulu_translations.machine_translator');

        $services->set('eekes_sulu_translations.machine_translation_runner', MachineTranslationRunner::class)
            ->args([
                service('eekes_sulu_translations.machine_translator'),
                service('eekes_sulu_translations.translation_repository'),
                service('eekes_sulu_translations.manager'),
                service('eekes_sulu_translations.placeholder_protector'),
                service('eekes_sulu_translations.icu_message_splitter'),
                service('eekes_sulu_translations.icu_validator'),
                $machine['batch_size'],
                $machine['max_per_run'],
                service('logger')->nullOnInvalid(),
            ])
            ->tag('monolog.logger', ['channel' => 'translations'])
            ->public();
    }

    private function shouldCollectMissing(string $mode, ContainerBuilder $builder): bool
    {
        return match ($mode) {
            'always' => true,
            'never' => false,
            default => (bool) $builder->getParameter('kernel.debug'),
        };
    }
}

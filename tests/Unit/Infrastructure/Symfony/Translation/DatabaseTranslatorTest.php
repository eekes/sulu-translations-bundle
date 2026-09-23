<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Infrastructure\Symfony\Translation;

use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\MessageCatalogueEnricher;
use Eekes\SuluTranslationsBundle\Application\Recorder\MissingMessageRecorder;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Translation\DatabaseTranslator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * Against the real Symfony translator, not a mock of it. The contract being verified is
 * Symfony's, and a mocked catalogue would only confirm our own idea of how it behaves.
 */
class DatabaseTranslatorTest extends TestCase
{
    public function testTheDatabaseWinsOverTheFile(): void
    {
        $translator = $this->translator(
            ['nl' => ['messages' => ['greeting' => 'uit het bestand']]],
            ['nl' => ['messages' => ['greeting' => 'uit de database']]],
        );

        self::assertSame('uit de database', $translator->trans('greeting', [], 'messages', 'nl'));
    }

    public function testAKeyOnlyInTheFileStillResolves(): void
    {
        $translator = $this->translator(
            ['nl' => ['messages' => ['only_file' => 'uit het bestand']]],
            ['nl' => ['messages' => ['other' => 'uit de database']]],
        );

        self::assertSame('uit het bestand', $translator->trans('only_file', [], 'messages', 'nl'));
    }

    public function testPlaceholdersAreStillTheFormattersJob(): void
    {
        $translator = $this->translator([], ['nl' => ['messages' => ['hello' => 'Hallo %name%']]]);

        // The whole reason this class decorates instead of reimplementing trans().
        self::assertSame('Hallo Ada', $translator->trans('hello', ['%name%' => 'Ada'], 'messages', 'nl'));
    }

    public function testAnIcuValueIsFormattedByTheIcuFormatter(): void
    {
        if (!\class_exists(\MessageFormatter::class)) {
            self::markTestSkipped('The intl extension is required.');
        }

        $translator = $this->translator(
            [],
            ['en' => ['messages+intl-icu' => ['items' => '{count, plural, one {# item} other {# items}}']]],
        );

        self::assertSame('1 item', $translator->trans('items', ['count' => 1], 'messages', 'en'));
        self::assertSame('5 items', $translator->trans('items', ['count' => 5], 'messages', 'en'));
    }

    public function testTheFallbackLocaleIsHonouredForDatabaseValuesToo(): void
    {
        $translator = $this->translator(
            [],
            ['en' => ['messages' => ['only_en' => 'from the English database']]],
            fallbacks: ['en'],
        );

        self::assertSame('from the English database', $translator->trans('only_en', [], 'messages', 'fr'));
    }

    public function testAnUnknownKeyIsRecorded(): void
    {
        $recorder = $this->createMock(MissingMessageRecorder::class);
        $recorder->method('isEnabled')->willReturn(true);
        $recorder->expects(self::once())->method('recordMissing')->with('nothing_knows_this', 'messages');
        $recorder->expects(self::never())->method('recordUsed');

        $this->translator([], [], recorder: $recorder)->trans('nothing_knows_this', [], 'messages', 'nl');
    }

    public function testAResolvedKeyIsRecordedAsUsed(): void
    {
        $recorder = $this->createMock(MissingMessageRecorder::class);
        $recorder->method('isEnabled')->willReturn(true);
        $recorder->expects(self::once())->method('recordUsed')->with('greeting', 'messages');
        $recorder->expects(self::never())->method('recordMissing');

        $this->translator(['nl' => ['messages' => ['greeting' => 'hallo']]], [], recorder: $recorder)
            ->trans('greeting', [], 'messages', 'nl');
    }

    public function testWarmingUpNeverTouchesTheDatabase(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::never())->method('loadCatalogue');

        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->expects(self::never())->method('getFingerprint');

        $inner = new class('nl') extends Translator implements WarmableInterface {
            public function warmUp(string $cacheDir, ?string $buildDir = null): array
            {
                return ['warmed'];
            }
        };

        $versionProvider = new CatalogueVersionProvider($state);
        $translator = new DatabaseTranslator(
            $inner,
            new MessageCatalogueEnricher(new CatalogueProvider($repository, $versionProvider), $versionProvider),
        );

        // `cache:clear` runs on a machine whose migrations may not have run and whose database
        // may not be reachable at all.
        self::assertSame(['warmed'], $translator->warmUp('/tmp'));
    }

    /**
     * @param array<string, array<string, array<string, string>>> $fileMessages
     * @param array<string, array<string, array<string, string>>> $databaseMessages
     * @param list<string> $fallbacks
     */
    private function translator(
        array $fileMessages,
        array $databaseMessages,
        array $fallbacks = [],
        ?MissingMessageRecorder $recorder = null,
    ): DatabaseTranslator {
        $inner = new Translator('en');
        $inner->addLoader('array', new ArrayLoader());
        $inner->setFallbackLocales($fallbacks);

        foreach ($fileMessages as $locale => $domains) {
            foreach ($domains as $domain => $messages) {
                $inner->addResource('array', $messages, $locale, $domain);
            }
        }

        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->method('loadCatalogue')->willReturnCallback(
            static fn (string $locale): array => $databaseMessages[$locale] ?? [],
        );

        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willReturn('abc.1');

        $versionProvider = new CatalogueVersionProvider($state);

        return new DatabaseTranslator(
            $inner,
            new MessageCatalogueEnricher(new CatalogueProvider($repository, $versionProvider), $versionProvider),
            $recorder,
        );
    }
}

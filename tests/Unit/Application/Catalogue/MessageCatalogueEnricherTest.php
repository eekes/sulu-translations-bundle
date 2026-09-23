<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Catalogue;

use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\MessageCatalogueEnricher;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;

class MessageCatalogueEnricherTest extends TestCase
{
    public function testTheDatabaseOverwritesTheFile(): void
    {
        $catalogue = new MessageCatalogue('nl', ['messages' => ['a' => 'from the file', 'b' => 'only in the file']]);

        $this->enricher(['nl' => ['messages' => ['a' => 'from the database']]])->enrich($catalogue);

        self::assertSame('from the database', $catalogue->get('a'));
        self::assertSame('only in the file', $catalogue->get('b'));
    }

    public function testEveryCatalogueInTheFallbackChainIsEnriched(): void
    {
        $dutch = new MessageCatalogue('nl', ['messages' => []]);
        $english = new MessageCatalogue('en', ['messages' => ['only_en' => 'from the file']]);
        $dutch->addFallbackCatalogue($english);

        $this->enricher([
            'nl' => ['messages' => ['only_nl' => 'nl from the database']],
            'en' => ['messages' => ['only_en' => 'en from the database']],
        ])->enrich($dutch);

        self::assertSame('nl from the database', $dutch->get('only_nl'));
        // Resolved through the fallback catalogue, which only works because that one was
        // enriched too.
        self::assertSame('en from the database', $dutch->get('only_en'));
    }

    public function testAnIcuMessageLandsInTheIcuDomain(): void
    {
        $catalogue = new MessageCatalogue('en');

        $this->enricher(['en' => ['messages+intl-icu' => ['items' => '{count, plural, one {# item} other {# items}}']]])
            ->enrich($catalogue);

        // Symfony's own trans() looks at the `+intl-icu` domain to decide which formatter to
        // use, so this is what makes an ICU value written in the admin behave like one in a
        // file.
        self::assertTrue($catalogue->defines('items', 'messages+intl-icu'));
        self::assertTrue($catalogue->defines('items', 'messages'));
    }

    public function testACatalogueIsEnrichedOnlyOncePerVersion(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('loadCatalogue')
            ->willReturn(['messages' => ['a' => 'from the database']]);

        $enricher = $this->enricherWith($repository, 'abc.7');
        $catalogue = new MessageCatalogue('nl');

        $enricher->enrich($catalogue);
        $enricher->enrich($catalogue);
        $enricher->enrich($catalogue);
    }

    public function testNothingHappensWhenTheDatabaseCannotAnswer(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::never())->method('loadCatalogue');

        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willThrowException(new \RuntimeException('no such table'));

        $versionProvider = new CatalogueVersionProvider($state);
        $enricher = new MessageCatalogueEnricher(
            new CatalogueProvider($repository, $versionProvider),
            $versionProvider,
        );

        $catalogue = new MessageCatalogue('nl', ['messages' => ['a' => 'from the file']]);
        $enricher->enrich($catalogue);

        self::assertSame('from the file', $catalogue->get('a'));
    }

    /**
     * @param array<string, array<string, array<string, string>>> $messages
     */
    private function enricher(array $messages): MessageCatalogueEnricher
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->method('loadCatalogue')->willReturnCallback(
            static fn (string $locale): array => $messages[$locale] ?? [],
        );

        return $this->enricherWith($repository, 'abc.3');
    }

    private function enricherWith(TranslationRepositoryInterface $repository, string $version): MessageCatalogueEnricher
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willReturn($version);

        $versionProvider = new CatalogueVersionProvider($state);

        return new MessageCatalogueEnricher(
            new CatalogueProvider($repository, $versionProvider),
            $versionProvider,
        );
    }
}

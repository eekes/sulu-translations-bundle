<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Catalogue;

use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueProvider;
use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class CatalogueProviderTest extends TestCase
{
    public function testItReturnsNothingWhenTheDatabaseIsUnreachable(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->method('loadCatalogue')->willThrowException(new \RuntimeException('connection refused'));

        // The translator runs in front of every page, including the error page. A throw here
        // is a white screen on a site whose only problem was a database hiccup.
        self::assertSame([], $this->provider($repository)->getMessages('nl'));
    }

    public function testItReturnsNothingWhenNothingHasEverBeenWritten(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::never())->method('loadCatalogue');

        // An empty fingerprint is the database answering "there is no state row", which is a
        // perfectly ordinary state on a fresh install.
        self::assertSame([], $this->provider($repository, version: '')->getMessages('nl'));
    }

    public function testAReEntrantCallDoesNotRecurse(): void
    {
        /** @var list<CatalogueProvider> $holder */
        $holder = [];
        $calls = 0;

        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->method('loadCatalogue')->willReturnCallback(
            static function () use (&$holder, &$calls): array {
                ++$calls;

                // Something inside Doctrine calls trans() while we are loading - a validator,
                // an exception message, a listener. Without the guard this is an endless
                // recursion with no usable stack trace.
                self::assertSame([], $holder[0]->getMessages('nl'));

                return ['messages' => ['a' => 'b']];
            },
        );

        $provider = $this->provider($repository);
        $holder[] = $provider;

        self::assertSame(['messages' => ['a' => 'b']], $provider->getMessages('nl'));
        self::assertSame(1, $calls);
    }

    public function testTheResultIsCachedPerVersion(): void
    {
        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::once())->method('loadCatalogue')->willReturn(['messages' => ['a' => 'b']]);

        $provider = $this->provider($repository, cache: new ArrayAdapter());

        $provider->getMessages('nl');
        $provider->reset();
        $provider->getMessages('nl');
    }

    public function testABumpedVersionIsACacheMiss(): void
    {
        $version = 'abc.1';

        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willReturnCallback(static function () use (&$version): string {
            return $version;
        });

        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('loadCatalogue')->willReturn(['messages' => ['a' => 'b']]);

        $versionProvider = new CatalogueVersionProvider($state);
        $provider = new CatalogueProvider($repository, $versionProvider, new ArrayAdapter());

        $provider->getMessages('nl');

        // Nothing is ever explicitly invalidated: the version is part of the cache key, so both
        // Sulu kernels miss at the same moment and neither can serve a stale string.
        // A fingerprint changes on every write, and changes wholesale when the table is
        // recreated - a restored backup must not be served a cache keyed on a counter that
        // started again at one.
        $version = 'abc.2';
        $versionProvider->reset();
        $provider->reset();

        $provider->getMessages('nl');
    }

    private function provider(
        TranslationRepositoryInterface $repository,
        string $version = 'abc.1',
        ?ArrayAdapter $cache = null,
    ): CatalogueProvider {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willReturn($version);

        return new CatalogueProvider($repository, new CatalogueVersionProvider($state), $cache);
    }
}

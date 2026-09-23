<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Catalogue;

use Eekes\SuluTranslationsBundle\Application\Catalogue\CatalogueVersionProvider;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use PHPUnit\Framework\TestCase;

class CatalogueVersionProviderTest extends TestCase
{
    public function testItReportsUnavailableWhenTheTableIsMissing(): void
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->method('getFingerprint')->willThrowException(new \RuntimeException('Base table or view not found'));

        // A fresh checkout warms its cache before the first migration runs. That is a normal
        // state, not an error.
        self::assertNull((new CatalogueVersionProvider($state))->getVersion());
    }

    public function testItDoesNotHammerADatabaseThatIsDown(): void
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->expects(self::once())
            ->method('getFingerprint')
            ->willThrowException(new \RuntimeException('connection refused'));

        $provider = new CatalogueVersionProvider($state);

        $provider->getVersion();
        $provider->getVersion();
        $provider->getVersion();
    }

    public function testWithoutATtlEveryCallAsksTheDatabase(): void
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->expects(self::exactly(2))->method('getFingerprint')->willReturn('abc.4');

        // The default: one tiny primary-key read per request, and an edit is live on the next
        // one.
        $provider = new CatalogueVersionProvider($state, 0);

        $provider->getVersion();
        $provider->getVersion();
    }

    public function testATtlMemoisesTheVersion(): void
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->expects(self::once())->method('getFingerprint')->willReturn('abc.4');

        $provider = new CatalogueVersionProvider($state, 60);

        $provider->getVersion();
        $provider->getVersion();
    }

    public function testResetForgetsTheMemoisedVersion(): void
    {
        $state = $this->createMock(CatalogueStateRepositoryInterface::class);
        $state->expects(self::exactly(2))->method('getFingerprint')->willReturn('abc.4');

        $provider = new CatalogueVersionProvider($state, 60);

        $provider->getVersion();
        $provider->reset();
        $provider->getVersion();
    }
}

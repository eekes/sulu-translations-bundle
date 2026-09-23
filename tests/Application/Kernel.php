<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Application;

use Eekes\SuluTranslationsBundle\SuluTranslationsBundle;
use Sulu\Bundle\TestBundle\Kernel\SuluTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * The bundle tests itself against this minimal application, never against a surrounding Sulu
 * skeleton: a suite that needs one cannot be run in CI or by an outside contributor.
 *
 * `$cacheSuffix` exists for the cross-kernel test. Sulu runs an admin and a website kernel with
 * separate cache directories, and the bundle's central claim is that a write in one is visible
 * in the other without a cache clear. Proving that needs two kernels that genuinely cannot
 * share a filesystem cache pool.
 */
class Kernel extends SuluTestKernel
{
    private string $cacheSuffix = '';

    public function setCacheSuffix(string $suffix): void
    {
        $this->cacheSuffix = $suffix;
    }

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();

        yield new SuluTranslationsBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__ . '/config/config.yaml');
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__ . '/var/cache/' . $this->getContext() . $this->cacheSuffix . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return __DIR__ . '/var/log';
    }
}

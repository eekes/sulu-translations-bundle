<?php

declare(strict_types=1);

/*
 * Hands phpstan-doctrine the entity manager of the test application, so it can check DQL and
 * repository return types against the real mapping.
 *
 * It boots into a cache directory of its own. PHPStan runs with its own autoloader, so a
 * container compiled from here is not one the test suite can use - sharing the directory leaves
 * the next PHPUnit run with a container that cannot find classes PHPStan never loaded.
 */

use Doctrine\Persistence\ManagerRegistry;
use Eekes\SuluTranslationsBundle\Tests\Application\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$kernel = new class('test', true) extends Kernel {
    public function getCacheDir(): string
    {
        return __DIR__ . '/Application/var/cache/phpstan';
    }
};

$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');

if (!$registry instanceof ManagerRegistry) {
    throw new \RuntimeException('The test application has no Doctrine registry.');
}

return $registry->getManager();

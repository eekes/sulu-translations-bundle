<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Tests\Application\Kernel;
use Psr\Container\ContainerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's entire premise, in one test.
 *
 * Sulu runs an admin kernel and a website kernel with separate cache directories, so a
 * filesystem cache pool written by one is invisible to the other. An edit in the admin still
 * has to reach the website on the very next request, with no cache clear and no deploy - which
 * is why the staleness signal lives in the database and not in a pool.
 *
 * Everything else in this bundle is a user interface on top of this.
 */
class CrossKernelTest extends TranslationsTestCase
{
    private ?Kernel $secondKernel = null;

    protected function tearDown(): void
    {
        $this->secondKernel?->shutdown();
        $this->secondKernel = null;

        parent::tearDown();
    }

    public function testAWriteInOneKernelIsSeenByTheOther(): void
    {
        // Warm the second kernel first, so it has cached a catalogue that does not contain the
        // value yet. Without that this test would pass for the wrong reason.
        self::assertSame('From the file, in English', $this->secondTranslator()->trans('homepage.title', [], 'messages', 'en'));

        self::givenTranslation('homepage.title', 'en', 'Written in the first kernel');

        // No cache:clear, no restart, no shared pool.
        self::assertSame('Written in the first kernel', $this->secondTranslator()->trans('homepage.title', [], 'messages', 'en'));
    }

    public function testASecondEditIsSeenAsWell(): void
    {
        self::givenTranslation('homepage.title', 'en', 'First');
        self::assertSame('First', $this->secondTranslator()->trans('homepage.title', [], 'messages', 'en'));

        $translation = self::requireKey('homepage.title')->getTranslation('en');
        self::assertNotNull($translation);
        self::getTranslationManager()->saveValue($translation, 'Second');

        self::assertSame('Second', $this->secondTranslator()->trans('homepage.title', [], 'messages', 'en'));
    }

    private function secondTranslator(): TranslatorInterface
    {
        if (null === $this->secondKernel) {
            $this->secondKernel = new Kernel('test', true);
            $this->secondKernel->setCacheSuffix('_second');
            $this->secondKernel->boot();
        }

        /** @var ContainerInterface $container */
        $container = $this->secondKernel->getContainer()->get('test.service_container');

        /** @var TranslatorInterface $translator */
        $translator = $container->get('translator');

        // The version is read once per process and memoised for the configured TTL, which is
        // zero here - but the enricher also memoises per catalogue object, and both have to be
        // told the request is over. In a real application `kernel.reset` does this.
        $container->get('eekes_sulu_translations.catalogue_version')->reset();
        $container->get('eekes_sulu_translations.catalogue_provider')->reset();
        $container->get('eekes_sulu_translations.catalogue_enricher')->reset();

        return $translator;
    }
}

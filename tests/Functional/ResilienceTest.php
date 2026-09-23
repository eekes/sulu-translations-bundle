<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The translator sits in front of every `trans()` in the application, including the ones on
 * the error page and the ones Symfony makes while the container is warming. None of the
 * states below is hypothetical, and none of them may be an exception.
 */
class ResilienceTest extends TranslationsTestCase
{
    private bool $schemaDropped = false;

    protected function tearDown(): void
    {
        if ($this->schemaDropped) {
            self::createSchema();
            $this->schemaDropped = false;
        }

        parent::tearDown();
    }

    public function testAMissingTableFallsBackToTheFiles(): void
    {
        self::givenTranslation('homepage.title', 'en', 'From the database');
        self::assertSame('From the database', $this->translator()->trans('homepage.title', [], 'messages', 'en'));

        $this->dropOurTables();
        self::resetCatalogueCaches();
        $this->startFreshProcess();

        // A fresh checkout warms its cache before the first `doctrine:migrations:migrate`.
        // That is an ordinary state, not an error, and the site has to render words.
        self::assertSame('From the file, in English', $this->translator()->trans('homepage.title', [], 'messages', 'en'));
    }

    /**
     * What a new PHP process has, and a test that already translated something does not.
     *
     * Enrichment mutates the catalogue object the translator memoises, so a value that has
     * already been added to it stays there for the life of the process even after the row is
     * gone. That is a real limitation in worker runtimes, it is written down in `ROADMAP.md`,
     * and pretending otherwise here would hide it.
     *
     * `setFallbackLocales()` is the public method that empties `Translator::$catalogues`.
     */
    private function startFreshProcess(): void
    {
        $translator = $this->translator();

        if ($translator instanceof \Symfony\Component\Translation\Translator) {
            $translator->setFallbackLocales(['en']);

            return;
        }

        // Our decorator forwards unknown calls to the one underneath.
        $translator->setFallbackLocales(['en']); // @phpstan-ignore method.notFound
    }

    public function testAMissingTableDoesNotBreakAnUnknownKeyEither(): void
    {
        $this->dropOurTables();
        self::resetCatalogueCaches();

        self::assertSame('nothing.knows.this', $this->translator()->trans('nothing.knows.this', [], 'messages', 'en'));
    }

    public function testTheDatabaseIsNotAskedAgainImmediatelyAfterAFailure(): void
    {
        $this->dropOurTables();
        self::resetCatalogueCaches();

        $connection = self::getEntityManager()->getConnection();
        $this->translator()->trans('homepage.title', [], 'messages', 'en');

        // The back-off is what keeps a site with a dead database from turning every page view
        // into a fresh connection attempt.
        $before = $connection->getNativeConnection();
        $this->translator()->trans('homepage.only_in_file', [], 'messages', 'en');

        self::assertSame($before, $connection->getNativeConnection());
    }

    private function dropOurTables(): void
    {
        $this->schemaDropped = true;

        $connection = self::getEntityManager()->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['tl_translation_version', 'tl_translation', 'tl_translation_key', 'tl_translation_state'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function translator(): TranslatorInterface
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        return $translator;
    }
}

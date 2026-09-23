<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The commands a deploy and a maintenance job run.
 */
class CommandTest extends TranslationsTestCase
{
    private ?string $migrationsDirectory = null;

    protected function tearDown(): void
    {
        if (null !== $this->migrationsDirectory) {
            (new Filesystem())->remove($this->migrationsDirectory);
            $this->migrationsDirectory = null;
        }

        parent::tearDown();
    }

    public function testImportIsDeploySafe(): void
    {
        self::givenTranslation('homepage.title', 'en', 'Edited by a person');

        $tester = $this->runCommand('eekes:translations:import', ['--path' => [__DIR__ . '/../Application/translations']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('kept (edited in the admin)', $tester->getDisplay());
        self::assertSame('Edited by a person', self::valueOf('homepage.title', 'en'));
    }

    public function testImportDryRunWritesNothing(): void
    {
        $tester = $this->runCommand('eekes:translations:import', [
            '--path' => [__DIR__ . '/../Application/translations'],
            '--dry-run' => true,
        ]);

        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
        self::assertNull(self::findKey('homepage.title'));
    }

    public function testImportSaysWhichDomainsItLeftAlone(): void
    {
        $tester = $this->runCommand('eekes:translations:import', ['--path' => [__DIR__ . '/../Application/translations']]);

        // Skipping silently is how this became a support question in the first place: a file
        // somebody can point at did not arrive and nothing on screen said why.
        self::assertStringContainsString('Left alone: admin', $tester->getDisplay());
        self::assertNull(self::findKey('sulu_media.ai_generated', 'admin'));
    }

    public function testSyncPlaceholdersFillsInWhatWasNeverDeclared(): void
    {
        self::givenTranslation('declared.late', 'en', 'Hello %name%');
        $key = self::requireKey('declared.late');
        $french = $key->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Bonjour %name%');

        // An install from before the column existed. The command is the one-off that fills it
        // in, and until it runs the write path falls back to reading the source row.
        $key->setPlaceholders(null);
        self::getEntityManager()->flush();

        $tester = $this->runCommand('eekes:translations:sync-placeholders');

        self::assertSame(0, $tester->getStatusCode());
        self::getEntityManager()->clear();
        self::assertSame(['%name%'], self::requireKey('declared.late')->getPlaceholders());
    }

    public function testSyncPlaceholdersReportsTheLanguagesThatAreIncomplete(): void
    {
        self::givenTranslation('incomplete.row', 'en', 'Hello %name%');

        // Written past the validator, the way a value that predates the check or an `off`
        // install ends up in the table.
        $french = self::requireKey('incomplete.row')->getTranslation('fr');
        self::assertNotNull($french);
        $french->setValue('Bonjour');
        self::getEntityManager()->flush();

        $tester = $this->runCommand('eekes:translations:sync-placeholders', ['--all' => true]);

        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('incomplete.row', $display);
        self::assertStringContainsString('%name%', $display);
    }

    public function testExportWritesFiles(): void
    {
        self::givenTranslation('exported.key', 'en', 'Exported value');

        $path = \sys_get_temp_dir() . '/sulu-translations-export-' . \bin2hex(\random_bytes(4));

        $tester = $this->runCommand('eekes:translations:export', ['--path' => $path, '--locale' => ['en']]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($path . '/messages.en.yaml');
        self::assertStringContainsString('Exported value', (string) \file_get_contents($path . '/messages.en.yaml'));

        \array_map('\unlink', \glob($path . '/*') ?: []);
        \rmdir($path);
    }

    public function testExportSkipsEmptyValues(): void
    {
        self::givenTranslation('half.translated', 'en', 'Only English');

        $path = \sys_get_temp_dir() . '/sulu-translations-export-' . \bin2hex(\random_bytes(4));
        $this->runCommand('eekes:translations:export', ['--path' => $path]);

        // An empty entry in a YAML file shadows nothing but renders as an empty string on the
        // page, which is worse than the key showing through.
        self::assertFileDoesNotExist($path . '/messages.fr.yaml');

        \array_map('\unlink', \glob($path . '/*') ?: []);
        \rmdir($path);
    }

    public function testStatusReportsPerLocale(): void
    {
        self::givenTranslation('status.cmd', 'en', 'English only');

        $tester = $this->runCommand('eekes:translations:status');

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();

        foreach (['en', 'nl', 'fr', 'de'] as $locale) {
            self::assertStringContainsString($locale, $display);
        }
    }

    public function testStatusCanFailABuild(): void
    {
        self::givenTranslation('status.gate', 'en', 'English only');

        $tester = $this->runCommand('eekes:translations:status', ['--fail-on-missing' => true]);

        // A release gate: three locales have nothing for this key.
        self::assertSame(1, $tester->getStatusCode());
    }

    public function testSyncLocalesCreatesTheMissingRows(): void
    {
        self::givenTranslation('sync.cmd', 'en', 'Something');

        self::getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM tl_translation WHERE locale = :locale',
            ['locale' => 'fr'],
        );
        self::getEntityManager()->clear();

        $tester = $this->runCommand('eekes:translations:sync-locales');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Created 1 rows', $tester->getDisplay());
    }

    public function testPruneReportsBeforeItDeletes(): void
    {
        self::givenTranslation('orphan.cmd', 'en', 'Old');
        $key = self::requireKey('orphan.cmd');
        $key->setOrphanedAt(new \DateTimeImmutable('-200 days'));
        self::getKeyRepository()->save($key);

        $tester = $this->runCommand('eekes:translations:prune');

        self::assertStringContainsString('would be deleted', $tester->getDisplay());
        self::assertNotNull(self::findKey('orphan.cmd'));

        $tester = $this->runCommand('eekes:translations:prune', ['--force' => true]);

        self::assertStringContainsString('Deleted 1 keys', $tester->getDisplay());
        self::assertNull(self::findKey('orphan.cmd'));
    }

    public function testPruneLeavesProtectedKeysAlone(): void
    {
        self::givenTranslation('orphan.protected', 'en', 'Old but needed');
        $key = self::requireKey('orphan.protected');
        $key->setOrphanedAt(new \DateTimeImmutable('-200 days'));
        $key->setProtected(true);
        self::getKeyRepository()->save($key);

        $this->runCommand('eekes:translations:prune', ['--force' => true]);

        self::assertNotNull(self::findKey('orphan.protected'));
    }

    public function testPruneWritesTheMigrationThatCarriesTheDeletion(): void
    {
        self::givenTranslation('orphan.migrated', 'en', 'Old');
        $key = self::requireKey('orphan.migrated');
        $key->setOrphanedAt(new \DateTimeImmutable('-200 days'));
        self::getKeyRepository()->save($key);

        $directory = \sys_get_temp_dir() . '/sulu-translations-prune-' . \bin2hex(\random_bytes(4));

        $tester = $this->runCommand('eekes:translations:prune', [
            '--force' => true,
            '--migration' => true,
            '--migrations-dir' => $directory,
            '--namespace' => 'DoctrineMigrations',
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertNull(self::findKey('orphan.migrated'));

        $files = \glob($directory . '/Version*.php') ?: [];
        self::assertCount(1, $files);

        // Without this the key stays in every other database for good, because nothing else
        // in a release ever deletes a translation.
        $source = (string) \file_get_contents($files[0]);
        self::assertStringContainsString('DELETE FROM tl_translation_key', $source);
        self::assertStringContainsString($key->getKeyHash(), $source);

        \array_map('\unlink', $files);
        \rmdir($directory);
    }

    public function testPruneWithoutForceWritesNoMigration(): void
    {
        self::givenTranslation('orphan.reported', 'en', 'Old');
        $key = self::requireKey('orphan.reported');
        $key->setOrphanedAt(new \DateTimeImmutable('-200 days'));
        self::getKeyRepository()->save($key);

        $directory = \sys_get_temp_dir() . '/sulu-translations-prune-' . \bin2hex(\random_bytes(4));

        $tester = $this->runCommand('eekes:translations:prune', [
            '--migration' => true,
            '--migrations-dir' => $directory,
        ]);

        self::assertStringContainsString('--force --migration', $tester->getDisplay());
        self::assertNotNull(self::findKey('orphan.reported'));
        self::assertFileDoesNotExist($directory);
    }

    public function testTranslateSaysSoWhenNoTranslatorIsConfigured(): void
    {
        $tester = $this->runCommand('eekes:translations:translate', ['--to' => 'nl']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No machine translator is configured', $tester->getDisplay());
    }

    public function testGenerateMigrationFromTheDatabaseIsADryRunAway(): void
    {
        self::givenTranslation('release.key', 'en', 'Filled in locally');

        $tester = $this->runCommand('eekes:translations:generate-migration', [
            '--from' => 'database',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('the database', $tester->getDisplay());
        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
    }

    public function testGenerateMigrationFromTheDatabaseSaysWhenThereIsNothingLeftToCarry(): void
    {
        $tester = $this->runCommand('eekes:translations:generate-migration', [
            '--from' => 'database',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to carry', $tester->getDisplay());
    }

    public function testGenerateMigrationAsksBeforeItWrites(): void
    {
        self::givenTranslation('release.key', 'en', 'Filled in locally');
        $directory = $this->givenMigrationsDirectory();

        $tester = $this->runCommand('eekes:translations:generate-migration', [
            '--from' => 'database',
            '--migrations-dir' => $directory,
        ], ['no']);

        // The counts come first and the file after the answer: what a migration carries is
        // decided by a config setting, a delta and a --domain, and the moment to notice they
        // were wrong is before the file exists.
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Write this migration?', $tester->getDisplay());
        self::assertStringContainsString('Nothing was written', $tester->getDisplay());
        self::assertSame([], \glob($directory . '/Version*.php'));
    }

    public function testGenerateMigrationWritesOnceItIsConfirmed(): void
    {
        self::givenTranslation('release.key', 'en', 'Filled in locally');
        $directory = $this->givenMigrationsDirectory();

        $tester = $this->runCommand('eekes:translations:generate-migration', [
            '--from' => 'database',
            '--migrations-dir' => $directory,
        ], ['yes']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, (array) \glob($directory . '/Version*.php'));
    }

    public function testGenerateMigrationDoesNotAskADeployScript(): void
    {
        self::givenTranslation('release.key', 'en', 'Filled in locally');
        $directory = $this->givenMigrationsDirectory();

        // --no-interaction, which is what a CI job and a deploy run with. A prompt that blocks
        // there would be a worse bug than the one the prompt is for.
        $tester = $this->runCommand('eekes:translations:generate-migration', [
            '--from' => 'database',
            '--migrations-dir' => $directory,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, (array) \glob($directory . '/Version*.php'));
    }

    public function testGenerateMigrationRejectsAnUnknownSource(): void
    {
        $tester = $this->runCommand('eekes:translations:generate-migration', ['--from' => 'crowdin']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--from=files or --from=database', $tester->getDisplay());
    }

    private function givenMigrationsDirectory(): string
    {
        $this->migrationsDirectory = \sys_get_temp_dir() . '/sulu-translations-command-' . \bin2hex(\random_bytes(4));
        (new Filesystem())->mkdir($this->migrationsDirectory);

        return $this->migrationsDirectory;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $answers what a prompt gets, in order
     */
    private function runCommand(string $name, array $input = [], array $answers = []): CommandTester
    {
        $application = self::createConsoleApplication();
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($name));

        if ([] !== $answers) {
            $tester->setInputs($answers);
            $tester->execute($input, ['interactive' => true]);

            return $tester;
        }

        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}

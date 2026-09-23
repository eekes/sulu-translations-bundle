<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Io\MigrationGenerator;
use Eekes\SuluTranslationsBundle\Application\Io\MigrationStatement;
use Eekes\SuluTranslationsBundle\Domain\Model\CatalogueState;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Files in, SQL out - and then that SQL is actually executed here, twice.
 *
 * Asserting on the generated string would only prove it looks the way it looked when it was
 * written. The properties that matter - it runs, it is portable, it is idempotent, it never
 * overwrites an edited value - can only be shown by running it against a real database.
 */
class MigrationGeneratorTest extends TranslationsTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = \sys_get_temp_dir() . '/sulu-translations-migration-' . \bin2hex(\random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testItReadsEveryFormatSymfonyCanRead(): void
    {
        $this->givenXliff('messages.nl.xlf', ['checkout.pay' => 'Betalen']);
        $this->givenYaml('messages.en.yaml', ['checkout.pay' => 'Pay']);
        $this->givenYaml('validators.nl.yaml', ['field.required' => 'Verplicht veld']);

        $this->execute($this->generate());

        // XLIFF and YAML, two domains, in one pass - the reader is Symfony's, so a project on
        // .po or .csv is covered by the same code.
        self::assertSame('Betalen', self::valueOf('checkout.pay', 'nl'));
        self::assertSame('Pay', self::valueOf('checkout.pay', 'en'));
        self::assertSame('Verplicht veld', self::valueOf('field.required', 'nl', 'validators'));
    }

    public function testEveryKeyGetsARowInEveryLanguage(): void
    {
        $this->givenXliff('messages.nl.xlf', ['only.dutch' => 'Alleen Nederlands']);

        $this->execute($this->generate());

        // Without the empty rows the "not translated" screen cannot show the key at all.
        $key = self::requireKey('only.dutch');
        self::assertCount(4, $key->getTranslations());
        self::assertSame('Alleen Nederlands', $key->getTranslation('nl')?->getValue());
        self::assertNull($key->getTranslation('fr')?->getValue());
        self::assertFalse($key->getTranslation('fr')?->isTranslated());
    }

    public function testItIsSafeToRunTwice(): void
    {
        $this->givenYaml('messages.en.yaml', ['idempotent.key' => 'Once']);

        $statements = $this->generate();
        $this->execute($statements);
        $this->execute($statements);

        self::getEntityManager()->clear();

        self::assertSame(1, self::getKeyRepository()->countAll());
        self::assertCount(4, self::requireKey('idempotent.key')->getTranslations());
    }

    public function testItNeverOverwritesAValueSomebodyEdited(): void
    {
        $this->givenYaml('messages.en.yaml', ['edited.key' => 'From the file']);

        $statements = $this->generate();
        $this->execute($statements);

        $translation = self::requireKey('edited.key')->getTranslation('en');
        self::assertNotNull($translation);
        self::getTranslationManager()->saveValue($translation, 'Edited in the admin');

        // The same migration reaching production, where somebody already fixed the text.
        $this->execute($statements);
        self::getEntityManager()->clear();

        self::assertSame('Edited in the admin', self::valueOf('edited.key', 'en'));
    }

    public function testItFillsALanguageThatWasStillEmpty(): void
    {
        $this->givenYaml('messages.en.yaml', ['later.key' => 'English']);
        $this->execute($this->generate());

        // A second release adds the Dutch file.
        $this->givenYaml('messages.nl.yaml', ['later.key' => 'Nederlands']);
        $this->execute($this->generate());
        self::getEntityManager()->clear();

        self::assertSame('Nederlands', self::valueOf('later.key', 'nl'));
        self::assertSame('English', self::valueOf('later.key', 'en'));
    }

    public function testIcuMessagesKeepTheirFlag(): void
    {
        $this->givenYaml('messages+intl-icu.en.yaml', ['cart.items' => '{count, plural, one {# item} other {# items}}']);

        $this->execute($this->generate());
        self::getEntityManager()->clear();

        // The `+intl-icu` domain is a Symfony file convention; in the database it is a flag
        // on the key, and getting it wrong means the wrong formatter renders the value.
        $key = self::requireKey('cart.items');
        self::assertTrue($key->isIcu());
        self::assertSame('messages', $key->getDomain());
    }

    public function testAValueFullOfQuotesAndBackslashesSurvives(): void
    {
        $nasty = 'He said "hi" \\ don\'t -- {weird} %name%';
        $this->givenYaml('messages.en.yaml', ['nasty.key' => $nasty]);

        $this->execute($this->generate());
        self::getEntityManager()->clear();

        // `addSql()` takes bound parameters, which is the whole reason the generator never
        // escapes anything by hand.
        self::assertSame($nasty, self::valueOf('nasty.key', 'en'));
    }

    public function testTheBumpMakesTheNewTranslationsVisible(): void
    {
        $this->givenYaml('messages.en.yaml', ['bumped.key' => 'From the migration']);

        $this->execute($this->generate());
        self::resetCatalogueCaches();

        /** @var \Symfony\Contracts\Translation\TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        // A migration that loads rows but leaves the version counter alone would be served
        // from a stale catalogue on every machine that had already cached one.
        self::assertSame('From the migration', $translator->trans('bumped.key', [], 'messages', 'en'));
    }

    public function testItWritesARunnableMigrationClass(): void
    {
        $this->givenYaml('messages.en.yaml', ['written.key' => 'Value']);

        $generated = $this->generator()->generate([$this->directory]);
        $result = $this->generator()->write($generated, $this->directory, 'DoctrineMigrations');

        self::assertFileExists($result['path']);
        self::assertSame(1, $generated['keys']);

        $source = (string) \file_get_contents($result['path']);

        self::assertStringContainsString('extends AbstractMigration', $source);
        self::assertStringContainsString('$this->addSql(', $source);
        self::assertStringContainsString('throwIrreversibleMigrationException', $source);

        // A deploy that reaches this before the schema migration has to skip, not die
        // halfway through the release.
        self::assertStringContainsString('$this->skipIf(', $source);
        self::assertStringContainsString('tl_translation_key', $source);

        // The file has to be valid PHP before anyone finds out on a deploy.
        $lint = \shell_exec(\escapeshellcmd(\PHP_BINARY) . ' -l ' . \escapeshellarg($result['path']) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $lint);
    }

    public function testTheDatabaseSourceCarriesTheValuesFilledInLocally(): void
    {
        self::givenTranslation('local.key', 'en', 'Typed in the admin');
        self::givenTranslation('local.key', 'nl', 'In de admin getypt');

        $result = $this->generator()->generateFromDatabase();

        self::assertSame(1, $result['keys']);

        // The point of the whole thing: a completely fresh environment - a colleague, a new
        // staging database - gets the key and both languages from the release alone.
        static::purgeDatabase();
        $this->execute($result['statements']);

        self::assertSame('Typed in the admin', self::valueOf('local.key', 'en'));
        self::assertSame('In de admin getypt', self::valueOf('local.key', 'nl'));
    }

    public function testACarriedKeyIsCarriedInEveryLanguage(): void
    {
        self::givenTranslation('partial.key', 'en', 'Only English so far');

        $result = $this->generator()->generateFromDatabase();

        static::purgeDatabase();
        $this->execute($result['statements']);

        // Without the empty rows the "not translated" screen on the target cannot show the
        // key at all, which is exactly the screen a translator opens after a release.
        $key = self::requireKey('partial.key');
        self::assertCount(4, $key->getTranslations());
        self::assertSame('Only English so far', $key->getTranslation('en')?->getValue());
        self::assertNull($key->getTranslation('fr')?->getValue());
    }

    public function testAValueIsNeverCarriedTwice(): void
    {
        self::givenTranslation('shipped.key', 'en', 'Shipped once');

        $first = $this->generator()->generateFromDatabase();
        self::assertSame(1, $first['keys']);

        // Running the migration is what marks the rows, here as much as anywhere else.
        $this->execute($first['statements']);

        $second = $this->generator()->generateFromDatabase();

        self::assertSame(0, $second['keys']);
        self::assertSame([], $second['changed']);
    }

    public function testEverythingCanBeCarriedAgainOnDemand(): void
    {
        self::givenTranslation('shipped.key', 'en', 'Shipped once');
        $this->execute($this->generator()->generateFromDatabase()['statements']);

        // The escape hatch for a migration that was generated and then thrown away.
        $result = $this->generator()->generateFromDatabase(delta: false);

        self::assertSame(1, $result['keys']);
    }

    public function testAValueEditedAfterItWasShippedIsReportedAndLeftAlone(): void
    {
        self::givenTranslation('corrected.key', 'en', 'Frist');
        $this->execute($this->generator()->generateFromDatabase()['statements']);

        self::rewindMigratedAt();
        self::edit('corrected.key', 'en', 'First');

        $result = $this->generator()->generateFromDatabase();

        // A release cannot tell "the developer fixed a typo" from "an editor rewrote this
        // yesterday", so by default it reports and carries nothing.
        self::assertSame(0, $result['keys']);
        self::assertSame([['domain' => 'messages', 'key' => 'corrected.key', 'locale' => 'en']], $result['changed']);
    }

    public function testIncludeChangedCarriesItAndSaysSo(): void
    {
        self::givenTranslation('corrected.key', 'en', 'Frist');
        $this->execute($this->generator()->generateFromDatabase()['statements']);

        self::rewindMigratedAt();
        self::edit('corrected.key', 'en', 'First');

        $result = $this->generator()->generateFromDatabase(includeChanged: true);

        self::assertSame(1, $result['keys']);
        self::assertCount(1, $result['changed']);

        // An environment whose editor had typed something else loses it - which is why this
        // is opt-in and the command warns about it.
        static::purgeDatabase();
        self::givenTranslation('corrected.key', 'en', 'Typed on production');
        $this->execute($result['statements']);

        self::assertSame('First', self::valueOf('corrected.key', 'en'));
    }

    public function testADatabaseMigrationIsSafeToRunTwice(): void
    {
        self::givenTranslation('twice.key', 'en', 'Once');

        $statements = $this->generator()->generateFromDatabase()['statements'];

        static::purgeDatabase();
        $this->execute($statements);
        $this->execute($statements);

        self::assertSame(1, self::getKeyRepository()->countAll());
        self::assertCount(4, self::requireKey('twice.key')->getTranslations());
    }

    public function testADatabaseMigrationNeverOverwritesAnEditedValue(): void
    {
        self::givenTranslation('editable.key', 'en', 'From the developer');
        $statements = $this->generator()->generateFromDatabase()['statements'];

        // The same migration reaching production, where somebody already fixed the text.
        static::purgeDatabase();
        self::givenTranslation('editable.key', 'en', 'Edited in the admin');
        $this->execute($statements);

        self::assertSame('Edited in the admin', self::valueOf('editable.key', 'en'));
    }

    public function testADeletionMigrationTakesTheKeyAndEverythingUnderIt(): void
    {
        self::givenTranslation('pruned.key', 'en', 'Was here');
        self::givenTranslation('kept.key', 'en', 'Still here');

        $result = $this->generator()->generateDeletions([self::requireKey('pruned.key')], new \DateTimeImmutable('-90 days'));

        self::assertSame(1, $result['keys']);

        $this->execute($result['statements']);

        self::assertNull(self::findKey('pruned.key'));
        self::assertNotNull(self::findKey('kept.key'));

        // The values and the versions go with the key through the foreign keys, which is why
        // the generator writes one statement per key and not three.
        self::assertSame(0, $this->countOrphanedValues(), 'Deleting the key left its value rows behind.');
        self::assertSame(4, $this->countRows('tl_translation'), 'The other key lost its rows too.');
    }

    public function testADeletionMigrationKeepsAKeyTheTargetStillRenders(): void
    {
        self::givenTranslation('busy.key', 'en', 'Rendered every day on production');

        $key = self::requireKey('busy.key');
        $result = $this->generator()->generateDeletions([$key], new \DateTimeImmutable('-90 days'));

        // The target is the only machine that knows this. A key the extractor cannot see and
        // the developer has not touched in a year can still be in daily use on production.
        $key->setLastUsedAt(new \DateTimeImmutable());
        self::getKeyRepository()->save($key);

        $this->execute($result['statements']);

        self::assertNotNull(self::findKey('busy.key'));
    }

    public function testADeletionMigrationKeepsAProtectedKey(): void
    {
        self::givenTranslation('protected.key', 'en', 'Not yours to delete');

        $key = self::requireKey('protected.key');
        $result = $this->generator()->generateDeletions([$key], new \DateTimeImmutable('-90 days'));

        $key->setProtected(true);
        self::getKeyRepository()->save($key);

        $this->execute($result['statements']);

        self::assertNotNull(self::findKey('protected.key'));
    }

    public function testADeletionMigrationIsSafeToRunTwice(): void
    {
        self::givenTranslation('twice.deleted', 'en', 'Once');

        $statements = $this->generator()->generateDeletions(
            [self::requireKey('twice.deleted')],
            new \DateTimeImmutable('-90 days'),
        )['statements'];

        $this->execute($statements);
        $this->execute($statements);

        self::assertNull(self::findKey('twice.deleted'));
    }

    public function testADeletionMigrationBumpsTheVersionToo(): void
    {
        self::givenTranslation('bumped.away', 'en', 'Gone in a moment');

        $before = self::catalogueVersion();

        $this->execute($this->generator()->generateDeletions(
            [self::requireKey('bumped.away')],
            new \DateTimeImmutable('-90 days'),
        )['statements']);

        // A migration that removes rows and leaves the counter alone is served from a stale
        // catalogue everywhere that had already cached one.
        self::assertGreaterThan($before, self::catalogueVersion());
    }

    public function testItWritesARunnableDeletionMigrationClass(): void
    {
        self::givenTranslation('written.away', 'en', 'Value');

        $generated = $this->generator()->generateDeletions(
            [self::requireKey('written.away')],
            new \DateTimeImmutable('-90 days'),
        );
        $result = $this->generator()->write($generated, $this->directory, 'DoctrineMigrations');

        $source = (string) \file_get_contents($result['path']);

        self::assertStringContainsString('DELETE FROM tl_translation_key', $source);
        self::assertStringContainsString('$this->skipIf(', $source);
        self::assertStringContainsString('throwIrreversibleMigrationException', $source);

        // The same trap as on the insert side: the description and the irreversible message
        // are substituted into single-quoted strings, so an apostrophe in either is a parse
        // error in every migration the bundle writes.
        $lint = \shell_exec(\escapeshellcmd(\PHP_BINARY) . ' -l ' . \escapeshellarg($result['path']) . ' 2>&1');
        self::assertStringContainsString('No syntax errors', (string) $lint);
    }

    private function countRows(string $table): int
    {
        return (int) self::getEntityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function countOrphanedValues(): int
    {
        return (int) self::getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM tl_translation t'
            . ' LEFT JOIN tl_translation_key k ON k.id = t.translation_key_id'
            . ' WHERE k.id IS NULL',
        );
    }

    private static function catalogueVersion(): int
    {
        return (int) self::getEntityManager()->getConnection()->fetchOne(
            'SELECT version FROM tl_translation_state WHERE id = ?',
            [CatalogueState::ID],
        );
    }

    private static function edit(string $key, string $locale, string $value): void
    {
        $translation = self::requireKey($key)->getTranslation($locale);

        self::assertNotNull($translation);

        self::getTranslationManager()->saveValue($translation, $value);
    }

    /**
     * Pushes every `migrated_at` a minute into the past.
     *
     * `changed` and `migrated_at` are both second-precision, so a value shipped and edited
     * inside the same second is genuinely indistinguishable. Making the gap explicit is what
     * keeps these tests about the semantics rather than about the clock.
     */
    private static function rewindMigratedAt(): void
    {
        self::getEntityManager()->getConnection()->executeStatement(
            'UPDATE tl_translation SET migrated_at = ? WHERE migrated_at IS NOT NULL',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')],
        );

        self::getEntityManager()->clear();
    }

    public function testAnIgnoredDomainIsNotCarried(): void
    {
        $this->givenYaml('messages.nl.yaml', ['checkout.pay' => 'Betalen']);
        $this->givenYaml('admin.nl.yaml', ['sulu_media.origin' => 'Herkomst']);

        $result = $this->generator()->generate([$this->directory]);

        // A migration carries what the project owns. The admin catalogue is Sulu's, and a
        // project that renames a few of its labels has no business shipping a thousand rows
        // of it to every environment.
        self::assertSame(['admin'], $result['skipped']);

        $this->execute($result['statements']);

        self::assertSame('Betalen', self::valueOf('checkout.pay', 'nl'));
        self::assertNull(self::findKey('sulu_media.origin', 'admin'));
    }

    public function testAnIgnoredDomainIsCarriedWhenItIsAskedForByName(): void
    {
        $this->givenYaml('admin.nl.yaml', ['sulu_media.origin' => 'Herkomst']);

        $result = $this->generator()->generate([$this->directory], null, 'admin');

        self::assertSame([], $result['skipped']);

        $this->execute($result['statements']);

        self::assertSame('Herkomst', self::valueOf('sulu_media.origin', 'nl', 'admin'));
    }

    public function testTheDatabaseSourceCarriesAnIgnoredDomainSomebodyFilledInByHand(): void
    {
        self::givenTranslation('sulu_media.origin', 'nl', 'Herkomst', 'admin');

        $result = $this->generator()->generateFromDatabase();

        // Renaming one of Sulu's labels in the admin is a feature, and a feature that works
        // locally and nowhere else is a bug. The filter is about what the bundle creates by
        // itself, never about what somebody typed.
        self::assertSame(1, $result['keys']);

        static::purgeDatabase();
        $this->execute($result['statements']);

        self::assertSame('Herkomst', self::valueOf('sulu_media.origin', 'nl', 'admin'));
    }

    /**
     * @return list<MigrationStatement>
     */
    private function generate(): array
    {
        return $this->generator()->generate([$this->directory])['statements'];
    }

    /**
     * @param list<MigrationStatement> $statements
     */
    private function execute(array $statements): void
    {
        $connection = self::getEntityManager()->getConnection();

        foreach ($statements as $statement) {
            $connection->executeStatement($statement->sql, $statement->parameters);
        }

        self::getEntityManager()->clear();
    }

    private function generator(): MigrationGenerator
    {
        /** @var MigrationGenerator $generator */
        $generator = self::getContainer()->get(MigrationGenerator::class);

        return $generator;
    }

    /**
     * @param array<string, string> $messages
     */
    private function givenYaml(string $name, array $messages): void
    {
        $contents = '';

        foreach ($messages as $key => $value) {
            $contents .= \sprintf("%s: %s\n", $key, \json_encode($value, \JSON_UNESCAPED_UNICODE));
        }

        (new Filesystem())->dumpFile($this->directory . '/' . $name, $contents);
    }

    /**
     * @param array<string, string> $messages
     */
    private function givenXliff(string $name, array $messages): void
    {
        $units = '';
        $index = 0;

        foreach ($messages as $key => $value) {
            ++$index;
            $units .= \sprintf(
                '<trans-unit id="%d" resname="%s"><source>%s</source><target>%s</target></trans-unit>',
                $index,
                \htmlspecialchars($key, \ENT_XML1),
                \htmlspecialchars($key, \ENT_XML1),
                \htmlspecialchars($value, \ENT_XML1),
            );
        }

        (new Filesystem())->dumpFile(
            $this->directory . '/' . $name,
            '<?xml version="1.0"?><xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">'
            . '<file source-language="en" datatype="plaintext" original="file.ext"><body>'
            . $units
            . '</body></file></xliff>',
        );
    }
}

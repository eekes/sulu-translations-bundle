<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Io\CodeScanner;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Static extraction, and the rule that keeps it from deleting live strings.
 */
class CodeScannerTest extends TranslationsTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = \sys_get_temp_dir() . '/sulu-translations-scan-' . \bin2hex(\random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);

        parent::tearDown();
    }

    public function testItFindsKeysInTemplatesAndRecordsWhereTheyAre(): void
    {
        $this->givenTemplate('page.html.twig', "{{ 'scanned.key'|trans }}\n{{ 'other.key'|trans({}, 'validators') }}\n");

        $summary = $this->scanner()->scan([$this->directory]);

        self::assertSame(2, $summary['seen']);
        self::assertSame(2, $summary['created']);

        $key = self::requireKey('scanned.key');
        self::assertSame(TranslationSource::Code, $key->getSource());

        $occurrences = $key->getOccurrences();
        self::assertNotSame([], $occurrences);
        self::assertStringEndsWith('page.html.twig', $occurrences[0]['path']);
        self::assertSame(1, $occurrences[0]['line']);

        // The domain an editor sees, not the file the key happened to be in.
        self::assertNotNull(self::findKey('other.key', 'validators'));
    }

    public function testAKeyThatDisappearedIsFlaggedNotDeleted(): void
    {
        $this->givenTemplate('page.html.twig', "{{ 'still.there'|trans }}\n");
        self::givenTranslation('gone.from.code', 'en', 'Still says something');

        $summary = $this->scanner()->scan([$this->directory], markOrphans: true);

        self::assertSame(1, $summary['orphaned']);

        // Twig extensions, keys built from an enum and keys concatenated at runtime are all
        // invisible to a static extractor. Deleting on that evidence removes strings that are
        // very much in use.
        self::assertNotNull(self::findKey('gone.from.code'));
        self::assertTrue(self::requireKey('gone.from.code')->isOrphaned());
    }

    public function testAKeyThatCameBackIsUnflagged(): void
    {
        self::givenTranslation('back.again', 'en', 'Something');
        $key = self::requireKey('back.again');
        $key->setOrphanedAt(new \DateTimeImmutable('-10 days'));
        self::getKeyRepository()->save($key);

        $this->givenTemplate('page.html.twig', "{{ 'back.again'|trans }}\n");

        $summary = $this->scanner()->scan([$this->directory], markOrphans: true);

        self::assertSame(1, $summary['unorphaned']);
        self::assertFalse(self::requireKey('back.again')->isOrphaned());
    }

    public function testTheLastKeyGetsItsOccurrencesToo(): void
    {
        // `ensure()` flushes per key, so every occurrence write used to ride along with the
        // next one and the last key silently ended up with none. Whichever key the extractor
        // happens to return last is the one that breaks, which is why this asserts on all of
        // them rather than on one.
        $this->givenTemplate('page.html.twig', "{{ 'first.key'|trans }}\n{{ 'second.key'|trans }}\n{{ 'third.key'|trans }}\n");

        $this->scanner()->scan([$this->directory]);
        self::getEntityManager()->clear();

        foreach (['first.key', 'second.key', 'third.key'] as $key) {
            self::assertNotSame([], self::requireKey($key)->getOccurrences(), \sprintf('"%s" has no occurrences.', $key));
        }
    }

    public function testItRecordsTemplatesAndPhpFilesInOneList(): void
    {
        // The panel answers "where is this text used", and the answer spans both kinds of
        // file. A key found only by one of the two would make the list quietly misleading.
        $this->givenTemplate('page.html.twig', "{{ 'shared.key'|trans }}\n");
        $this->givenTemplate('Mailer.php', "<?php\n\$this->translator->trans('shared.key');\n");

        $this->scanner()->scan([$this->directory]);
        self::getEntityManager()->clear();

        $paths = \array_column(self::requireKey('shared.key')->getOccurrences(), 'path');
        $extensions = \array_map(
            static fn (string $path): string => \pathinfo($path, \PATHINFO_EXTENSION),
            $paths,
        );

        \sort($extensions);

        self::assertSame(['php', 'twig'], $extensions);
    }

    public function testEveryUseIsRecordedNotJustTheFirstPerFile(): void
    {
        $lines = [];

        for ($index = 1; $index <= 12; ++$index) {
            $lines[] = "{{ 'repeated.key'|trans }}";
        }

        $this->givenTemplate('page.html.twig', \implode("\n", $lines) . "\n");

        $this->scanner()->scan([$this->directory]);
        self::getEntityManager()->clear();

        // Twelve uses, twelve line numbers - the admin groups them per file for display, but
        // the data behind it has to be complete.
        self::assertCount(12, self::requireKey('repeated.key')->getOccurrences());
    }

    public function testADryRunWritesNothing(): void
    {
        $this->givenTemplate('page.html.twig', "{{ 'dry.run.key'|trans }}\n");

        $summary = $this->scanner()->scan([$this->directory], dryRun: true);

        self::assertSame(1, $summary['created']);
        self::assertNull(self::findKey('dry.run.key'));
    }

    public function testAnIgnoredDomainIsCountedButNotCreated(): void
    {
        $this->givenTemplate('page.html.twig', "{{ 'scanned.key'|trans }}\n{{ 'sulu_admin.save'|trans({}, 'admin') }}\n");

        $summary = $this->scanner()->scan([$this->directory]);

        self::assertSame(2, $summary['seen']);
        self::assertSame(1, $summary['created']);
        self::assertSame(1, $summary['skipped']);
        self::assertNull(self::findKey('sulu_admin.save', 'admin'));
    }

    public function testAnIgnoredDomainIsNotFlaggedAsOrphanedEither(): void
    {
        // The key is in the code and in the database because somebody added it on purpose.
        // Skipping the domain must not turn "the bundle does not create this" into "nothing
        // uses this any more".
        self::givenTranslation('sulu_admin.save', 'en', 'Store it', 'admin');
        $this->givenTemplate('page.html.twig', "{{ 'sulu_admin.save'|trans({}, 'admin') }}\n");

        $summary = $this->scanner()->scan([$this->directory], markOrphans: true);

        self::assertSame(0, $summary['orphaned']);
        self::assertFalse(self::requireKey('sulu_admin.save', 'admin')->isOrphaned());
    }

    private function givenTemplate(string $name, string $contents): void
    {
        (new Filesystem())->dumpFile($this->directory . '/' . $name, $contents);
    }

    private function scanner(): CodeScanner
    {
        /** @var CodeScanner $scanner */
        $scanner = self::getContainer()->get('eekes_sulu_translations.scanner');

        return $scanner;
    }
}

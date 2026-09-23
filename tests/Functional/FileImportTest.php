<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Io\FileImporter;

/**
 * The deploy story. A release runs `eekes:translations:import`, its new keys appear, and
 * everything an editor changed since the last deploy survives untouched.
 */
class FileImportTest extends TranslationsTestCase
{
    private const PATHS = [__DIR__ . '/../Application/translations'];

    public function testItCreatesWhatIsMissing(): void
    {
        $result = $this->importer()->import(self::PATHS);

        self::assertGreaterThan(0, $result->keysCreated);
        self::assertSame('From the file, in English', self::valueOf('homepage.title', 'en'));
        self::assertSame('Uit het bestand, in het Nederlands', self::valueOf('homepage.title', 'nl'));
    }

    public function testItNeverOverwritesAnEditedValue(): void
    {
        self::givenTranslation('homepage.title', 'en', 'Edited by a person');

        $result = $this->importer()->import(self::PATHS);

        // Without this rule the bundle would lose a week of editing on every release, which
        // is exactly what `translation:pull` does.
        self::assertSame('Edited by a person', self::valueOf('homepage.title', 'en'));
        self::assertGreaterThan(0, $result->valuesSkipped);
    }

    public function testOverwriteIsOptedIntoExplicitly(): void
    {
        self::givenTranslation('homepage.title', 'en', 'Edited by a person');

        $this->importer()->import(self::PATHS, overwrite: true);

        self::assertSame('From the file, in English', self::valueOf('homepage.title', 'en'));
    }

    public function testAnImportMayRedefineThePlaceholdersOfAKey(): void
    {
        // English used to take an argument, a release took it out, and French has not caught
        // up. The import has to land: a deploy that fails over a sentence an editor typed is
        // a deploy nobody can finish. In the admin the same edit would be refused.
        self::givenTranslation('homepage.title', 'en', 'Old %name% title');
        $french = self::requireKey('homepage.title')->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Ancien titre %name%');

        $this->importer()->import(self::PATHS, overwrite: true);

        self::assertSame('From the file, in English', self::valueOf('homepage.title', 'en'));
        self::assertSame([], self::requireKey('homepage.title')->getPlaceholders());
    }

    public function testAPreviewWritesNothing(): void
    {
        $result = $this->importer()->preview(self::PATHS);

        self::assertTrue($result->dryRun);
        self::assertGreaterThan(0, $result->keysCreated);
        self::assertNotSame([], $result->samples);

        // A preview that leaves rows behind is not a preview.
        self::assertNull(self::findKey('homepage.title'));
    }

    public function testASecondImportChangesNothing(): void
    {
        $this->importer()->import(self::PATHS);
        $second = $this->importer()->import(self::PATHS);

        self::assertSame(0, $second->keysCreated);
        self::assertSame(0, $second->valuesCreated);
        self::assertSame(0, $second->valuesUpdated);
        self::assertGreaterThan(0, $second->valuesUnchanged);
    }

    public function testAnIcuFileSetsTheIcuFlag(): void
    {
        // `MessageCatalogue::all()` merges `messages+intl-icu` into `messages` and strips the
        // suffix, so the obvious check never fires and every imported plural message used to
        // land with `icu = false` - wrong formatter at runtime, no plural editor in the admin.
        $this->importer()->import(self::PATHS);

        self::assertTrue(self::requireKey('cart.items')->isIcu());
        self::assertSame('messages', self::requireKey('cart.items')->getDomain());
        self::assertFalse(self::requireKey('homepage.title')->isIcu());
    }

    public function testImportedKeysCanBeProtected(): void
    {
        $this->importer()->import(self::PATHS, protect: true);

        self::assertTrue(self::requireKey('homepage.title')->isProtected());
    }

    public function testAnIgnoredDomainIsLeftAlone(): void
    {
        // `translations/sulu/admin.*.json` is the shape this comes in: a project renames a
        // handful of Sulu's own labels, Symfony's reader walks the directory recursively, and
        // one import used to hand a translator the whole admin catalogue as work.
        $result = $this->importer()->import(self::PATHS);

        self::assertNull(self::findKey('sulu_media.ai_generated', 'admin'));
        self::assertSame(['admin'], $result->domainsSkipped);

        // Everything else in the same directory still lands.
        self::assertNotNull(self::findKey('homepage.title'));
    }

    public function testAnIgnoredDomainIsImportedWhenItIsAskedForByName(): void
    {
        // The list is what happens when nobody said. `--domain=admin` is somebody saying.
        $result = $this->importer()->import(self::PATHS, domain: 'admin');

        self::assertSame([], $result->domainsSkipped);
        self::assertSame('Generated by AI', self::valueOf('sulu_media.ai_generated', 'en', 'admin'));
        self::assertNull(self::findKey('homepage.title'));
    }

    public function testAPreviewReportsTheIgnoredDomainToo(): void
    {
        $result = $this->importer()->preview(self::PATHS);

        self::assertSame(['admin'], $result->domainsSkipped);

        foreach ($result->samples as $sample) {
            self::assertNotSame('admin', $sample['domain']);
        }
    }

    private function importer(): FileImporter
    {
        /** @var FileImporter $importer */
        $importer = self::getContainer()->get(FileImporter::class);

        return $importer;
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Translations;

use PHPUnit\Framework\TestCase;

/**
 * Every key the admin asks for has to exist somewhere.
 *
 * Symfony's translator returns the key itself when it cannot find it, so a typo or a borrowed
 * `sulu_admin.*` key that Sulu never defined does not fail, does not log and does not look
 * broken in a test - it just puts `sulu_admin.search` on a button in production. That is
 * exactly what happened to the confirm button of the bulk-action dialogs.
 */
class TranslationKeyUsageTest extends TestCase
{
    public function testEveryKeyTheAdminAsksForIsDefined(): void
    {
        $ours = $this->ownCatalogue();
        $sulus = $this->suluCatalogue();

        self::assertNotEmpty($sulus, 'Sulu\'s catalogues were not found, so this test proves nothing.');

        $used = $this->usedKeys();

        self::assertNotEmpty($used, 'No translation keys were found, so this test proves nothing.');

        foreach ($used as $key => $files) {
            $catalogue = \str_starts_with($key, 'eekes_sulu_translations.') ? $ours : $sulus;

            self::assertArrayHasKey(
                $key,
                $catalogue,
                \sprintf('"%s" is used in %s but no catalogue defines it.', $key, \implode(', ', $files)),
            );
        }
    }

    /**
     * The places a key can be written down, and the shape it takes in each. Anything else -
     * a service id, a tag name, a path - is not a translation key and must not be scanned as
     * one.
     *
     * @return array<string, list<string>>
     */
    private function usedKeys(): array
    {
        $patterns = [
            'assets/js' => ['/\btranslate\(\s*\'([a-z_]+\.[a-zA-Z0-9_.]+)\'/'],
            'config' => [
                '/<(?:title|info_text)>([a-z_]+\.[a-zA-Z0-9_.]+)<\//',
                '/\btranslation="([a-z_]+\.[a-zA-Z0-9_.]+)"/',
            ],
            'src' => ['/\bset(?:Tab)?Title\(\s*\'([a-z_]+\.[a-zA-Z0-9_.]+)\'/'],
        ];

        $used = [];

        foreach ($patterns as $directory => $expressions) {
            foreach ($this->files(\dirname(__DIR__, 3) . '/' . $directory) as $file) {
                $contents = (string) \file_get_contents($file->getPathname());

                foreach ($expressions as $expression) {
                    \preg_match_all($expression, $contents, $matches);

                    foreach ($matches[1] as $key) {
                        $used[$key][] = $file->getFilename();
                    }
                }
            }
        }

        return $used;
    }

    /**
     * @return \Iterator<\SplFileInfo>
     */
    private function files(string $directory): \Iterator
    {
        /** @var \Iterator<\SplFileInfo> $files */
        $files = new \CallbackFilterIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)),
            static fn (\SplFileInfo $file) => $file->isFile()
                && \in_array($file->getExtension(), ['js', 'php', 'xml'], true)
                && !\str_contains($file->getPathname(), '/node_modules/')
                && !\str_contains($file->getPathname(), '/tests/'),
        );

        return $files;
    }

    /**
     * @return array<string, string>
     */
    private function ownCatalogue(): array
    {
        /** @var array<string, string> $catalogue */
        $catalogue = \json_decode(
            (string) \file_get_contents(\dirname(__DIR__, 3) . '/translations/admin.en.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $catalogue;
    }

    /**
     * Sulu is a dev dependency, so its own catalogues are on disk here and there is no need to
     * guess which `sulu_admin.*` keys it ships.
     *
     * @return array<string, string>
     */
    private function suluCatalogue(): array
    {
        $catalogue = [];

        foreach (\glob(\dirname(__DIR__, 3) . '/vendor/sulu/sulu/src/Sulu/Bundle/*/Resources/translations/admin.en.json') ?: [] as $path) {
            /** @var array<string, string> $bundle */
            $bundle = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
            $catalogue = [...$catalogue, ...$bundle];
        }

        return $catalogue;
    }
}

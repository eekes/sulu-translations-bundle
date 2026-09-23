<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationsExportedEvent;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Writer\TranslationWriter;

/**
 * Writes the database back out to translation files.
 *
 * Two jobs, both real: a deploy-time fallback so a site whose database is unreachable still
 * renders words instead of keys, and a file an outside translator can be handed.
 *
 * It never writes an empty value. An empty entry in a YAML file shadows nothing but renders as
 * an empty string on the page, which is worse than the key showing through.
 */
class CatalogueExporter
{
    public function __construct(
        private readonly TranslationWriter $writer,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly LocaleProvider $localeProvider,
        private readonly Filesystem $filesystem,
        private readonly TranslationManagerInterface $manager,
        private readonly string $defaultPath,
        private readonly string $defaultFormat = 'yaml',
    ) {
    }

    /**
     * @param list<string>|null $locales
     *
     * @return array{path: string, format: string, locales: list<string>, messages: int}
     */
    public function export(
        ?array $locales = null,
        ?string $domain = null,
        ?string $format = null,
        ?string $path = null,
        bool $collectEvent = true,
    ): array {
        $locales ??= $this->localeProvider->getLocales();
        $format ??= $this->defaultFormat;
        $path ??= $this->defaultPath;

        $this->filesystem->mkdir($path);

        $written = 0;

        foreach ($locales as $locale) {
            $messages = $this->translationRepository->loadCatalogue($locale);

            if (null !== $domain) {
                $messages = \array_filter(
                    $messages,
                    static fn (string $catalogueDomain): bool => $catalogueDomain === $domain
                        || $catalogueDomain === $domain . MessageCatalogue::INTL_DOMAIN_SUFFIX,
                    \ARRAY_FILTER_USE_KEY,
                );
            }

            if ([] === $messages) {
                continue;
            }

            foreach ($messages as $domainMessages) {
                $written += \count($domainMessages);
            }

            $this->writer->write(
                new MessageCatalogue($locale, $messages),
                $format,
                ['path' => $path, 'default_locale' => $this->localeProvider->getDefaultLocale()],
            );
        }

        $summary = [
            'path' => $path,
            'format' => $format,
            'locales' => $locales,
            'messages' => $written,
        ];

        if ($collectEvent) {
            $this->manager->commit(new TranslationsExportedEvent([
                'format' => $format,
                'messages' => $written,
                'locales' => \implode(', ', $locales),
            ]));
        }

        return $summary;
    }

    /**
     * @return list<string>
     */
    public function getSupportedFormats(): array
    {
        /** @var list<string> $formats */
        $formats = $this->writer->getFormats();

        return $formats;
    }
}

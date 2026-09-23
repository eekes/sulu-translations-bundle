<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Locale;

use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;

/**
 * The locales this project translates.
 *
 * The union of every webspace's localizations, plus `eekes_sulu_translations.extra_locales` for the
 * strings no webspace serves - transactional mail, an app API, a PDF. Never a hardcoded list
 * and never `framework.enabled_locales` alone, because the set an editor sees in the language
 * switcher has to be the set the site actually has.
 */
class LocaleProvider
{
    /**
     * @var list<string>|null
     */
    private ?array $locales = null;

    /**
     * @param list<string> $extraLocales
     */
    public function __construct(
        private readonly WebspaceManagerInterface $webspaceManager,
        private readonly array $extraLocales,
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * @return list<string>
     */
    public function getLocales(): array
    {
        if (null !== $this->locales) {
            return $this->locales;
        }

        $locales = [];

        foreach ($this->webspaceManager->getAllLocalesByWebspaces() as $webspaceLocales) {
            foreach (\array_keys($webspaceLocales) as $locale) {
                $locales[(string) $locale] = true;
            }
        }

        foreach ($this->extraLocales as $locale) {
            $locales[$locale] = true;
        }

        // A project with no webspace at all (a test kernel, an API-only install) still has to
        // be able to translate something.
        if ([] === $locales) {
            $locales[$this->defaultLocale] = true;
        }

        $this->locales = \array_keys($locales);
        \sort($this->locales);

        return $this->locales;
    }

    public function getDefaultLocale(): string
    {
        $locales = $this->getLocales();

        return \in_array($this->defaultLocale, $locales, true) ? $this->defaultLocale : $locales[0];
    }

    public function supports(string $locale): bool
    {
        return \in_array($locale, $this->getLocales(), true);
    }

    public function reset(): void
    {
        $this->locales = null;
    }
}

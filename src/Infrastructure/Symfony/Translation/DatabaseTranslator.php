<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Translation;

use Eekes\SuluTranslationsBundle\Application\Catalogue\MessageCatalogueEnricher;
use Eekes\SuluTranslationsBundle\Application\Recorder\MissingMessageRecorder;
use Symfony\Component\HttpKernel\CacheWarmer\WarmableInterface;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Puts the database in front of the file catalogue, without reimplementing a word of
 * `Translator::trans()`.
 *
 * The inner translator keeps doing the hard parts - fallback resolution, choosing between the
 * ICU and the plain formatter, expanding `TranslatableInterface` parameters, plural selection.
 * All this class does is make sure the catalogue it reads already contains what the database
 * says. Reimplementing `trans()` is how a bundle ends up subtly wrong about plurals two
 * Symfony releases later.
 *
 * Registered with decoration priority 10, which puts it inside `translator.data_collector` and
 * `translator.logging`, so the profiler and the logs show what the site actually served.
 *
 * See the `runtime-catalogue` skill.
 */
class DatabaseTranslator implements TranslatorInterface, TranslatorBagInterface, LocaleAwareInterface, WarmableInterface
{
    /**
     * @param TranslatorInterface&TranslatorBagInterface&LocaleAwareInterface $inner
     */
    public function __construct(
        private readonly TranslatorInterface $inner,
        private readonly MessageCatalogueEnricher $enricher,
        private readonly ?MissingMessageRecorder $recorder = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function trans(?string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if (null === $id || '' === $id) {
            return '';
        }

        $catalogue = $this->getCatalogue($locale);

        if (null !== $this->recorder && $this->recorder->isEnabled()) {
            $this->record($catalogue, $id, $domain ?? 'messages');
        }

        return $this->inner->trans($id, $parameters, $domain, $locale);
    }

    public function getCatalogue(?string $locale = null): MessageCatalogueInterface
    {
        $catalogue = $this->bag()->getCatalogue($locale);
        $this->enricher->enrich($catalogue);

        return $catalogue;
    }

    /**
     * @return list<MessageCatalogueInterface>
     */
    public function getCatalogues(): array
    {
        $catalogues = \array_values([...$this->bag()->getCatalogues()]);

        foreach ($catalogues as $catalogue) {
            $this->enricher->enrich($catalogue);
        }

        return $catalogues;
    }

    public function setLocale(string $locale): void
    {
        $this->localeAware()->setLocale($locale);
    }

    public function getLocale(): string
    {
        return $this->localeAware()->getLocale();
    }

    /**
     * Warming must not touch the database: it runs during `cache:clear`, on a machine whose
     * migrations may not have run and whose database may not be reachable at all.
     *
     * @return string[]
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if (!$this->inner instanceof WarmableInterface) {
            return [];
        }

        return $this->inner->warmUp($cacheDir, $buildDir);
    }

    /**
     * Symfony's own translator decorators forward unknown calls, and so must we: consumers and
     * Sulu alike call `addResource()`, `setFallbackLocales()` and friends on the `translator`
     * service.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (!\method_exists($this->inner, $method)) {
            throw new \BadMethodCallException(\sprintf('The decorated translator "%s" has no method "%s".', \get_debug_type($this->inner), $method));
        }

        return $this->inner->{$method}(...$arguments);
    }

    private function record(MessageCatalogueInterface $catalogue, string $id, string $domain): void
    {
        if ($catalogue->has($id, $domain)) {
            $this->recorder?->recordUsed($id, $domain);

            return;
        }

        $this->recorder?->recordMissing($id, $domain);
    }

    private function bag(): TranslatorBagInterface
    {
        if (!$this->inner instanceof TranslatorBagInterface) {
            throw new \LogicException(\sprintf('The decorated translator "%s" must implement "%s".', \get_debug_type($this->inner), TranslatorBagInterface::class));
        }

        return $this->inner;
    }

    private function localeAware(): LocaleAwareInterface
    {
        if (!$this->inner instanceof LocaleAwareInterface) {
            throw new \LogicException(\sprintf('The decorated translator "%s" must implement "%s".', \get_debug_type($this->inner), LocaleAwareInterface::class));
        }

        return $this->inner;
    }
}

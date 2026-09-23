<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Catalogue;

use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * The database rows for one locale, shaped as `domain => key => value` and cached.
 *
 * Three things make this class more careful than it looks:
 *
 * 1. It runs in front of every `trans()` in the application. A throw here is a white screen on
 *    a page whose only problem was a missing table, so every failure degrades to "no database
 *    translations".
 * 2. Loading goes through Doctrine, and something down there can call `trans()` again - a
 *    validator, an exception message, a listener. Without the re-entrancy guard that is an
 *    endless recursion with no usable stack trace.
 * 3. The cache key contains the catalogue version, so nothing is ever explicitly invalidated
 *    and the two kernels cannot disagree about what is current.
 */
class CatalogueProvider
{
    private const CACHE_PREFIX = 'eekes_sulu_translations.catalogue.';

    /**
     * @var array<string, array<string, array<string, string>>>
     */
    private array $memo = [];

    private bool $loading = false;

    public function __construct(
        private readonly TranslationRepositoryInterface $repository,
        private readonly CatalogueVersionProvider $versionProvider,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, array<string, string>> catalogue domain => key => value
     */
    public function getMessages(string $locale): array
    {
        if ($this->loading) {
            // Re-entrant call while we are loading. Answering "nothing" is correct: the
            // messages we are about to return are not available yet.
            return [];
        }

        $version = $this->versionProvider->getVersion();

        if (null === $version || '' === $version) {
            return [];
        }

        $memoKey = $locale . '@' . $version;

        if (isset($this->memo[$memoKey])) {
            return $this->memo[$memoKey];
        }

        $this->loading = true;

        try {
            $messages = $this->load($locale, $version);
        } finally {
            $this->loading = false;
        }

        // Keep only the current version in memory; a long-running worker that sees a bump
        // should not accumulate every catalogue it has ever served.
        $this->memo = [$memoKey => $messages];

        return $messages;
    }

    /**
     * Drops the in-process memo. Tagged `kernel.reset`, and called by the write path so a save
     * is visible to the rest of the same request.
     */
    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function load(string $locale, string $version): array
    {
        if (null === $this->cache) {
            return $this->fetch($locale);
        }

        $cacheKey = self::CACHE_PREFIX . $locale . '.' . $version;

        try {
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                /** @var array<string, array<string, string>> $cached */
                $cached = $item->get();

                return $cached;
            }

            $messages = $this->fetch($locale);

            $item->set($messages);
            $this->cache->save($item);

            return $messages;
        } catch (\Throwable $throwable) {
            $this->logger?->warning('The translation catalogue cache is unavailable; reading the database directly.', [
                'exception' => $throwable,
            ]);

            return $this->fetch($locale);
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function fetch(string $locale): array
    {
        try {
            return $this->repository->loadCatalogue($locale);
        } catch (\Throwable $throwable) {
            $this->logger?->warning('Translations could not be read from the database; falling back to the file catalogue.', [
                'exception' => $throwable,
                'locale' => $locale,
            ]);

            return [];
        }
    }
}

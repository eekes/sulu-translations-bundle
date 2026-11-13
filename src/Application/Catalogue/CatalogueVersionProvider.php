<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Catalogue;

use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The one number that tells both Sulu kernels whether their cached catalogue is stale.
 *
 * It has to come from the database: the admin kernel and the website kernel have separate
 * cache directories, so a filesystem pool written by one is invisible to the other. See the
 * `runtime-catalogue` skill.
 */
class CatalogueVersionProvider
{
    /** The database could not answer. Callers serve the file catalogue. */
    public const UNAVAILABLE = '';

    /** Nothing has ever been written. There is nothing to add to the file catalogue. */
    public const NOTHING_WRITTEN = '';

    private const FAILURE_BACKOFF_SECONDS = 5.0;

    private ?string $version = null;

    private float $readAt = 0.0;

    /**
     * Timestamp until which the database is treated as unable to answer. Independent of the
     * TTL: the default TTL is zero, and "ask again on the very next page view" is exactly the
     * wrong behaviour towards a database that is down or a schema that does not exist yet.
     */
    private float $failedUntil = 0.0;

    public function __construct(
        private readonly CatalogueStateRepositoryInterface $repository,
        private readonly int $ttl = 0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The fingerprint of the current catalogue, or **null** when the database cannot answer -
     * a fresh checkout whose migrations have not run, or a database that is down. Callers
     * treat null as "there are no database translations", never as an error: the translator
     * may not fatal.
     *
     * An empty string means the database answered and has nothing: no translation has ever
     * been written.
     */
    public function getVersion(): ?string
    {
        $now = \microtime(true);

        if ($this->failedUntil > $now) {
            return null;
        }

        // Without a TTL the version is read once per request: every trans() call asks for it, and
        // `kernel.reset` (between requests and messages) and a write (TranslationManager::commit)
        // make it ask the database again.
        if (null !== $this->version && (0 === $this->ttl || $now - $this->readAt < $this->ttl)) {
            return $this->version;
        }

        try {
            $this->version = $this->repository->getFingerprint();
        } catch (\Throwable $throwable) {
            $this->logger?->warning('The translation catalogue version could not be read; falling back to the file catalogue.', [
                'exception' => $throwable,
            ]);

            $this->version = null;
            $this->failedUntil = $now + self::FAILURE_BACKOFF_SECONDS;

            return null;
        }

        $this->readAt = $now;

        return $this->version;
    }

    /**
     * Forgets the memoised version. Called after a write in the same process, and on
     * `kernel.reset` so a worker does not serve one request's version to the next.
     */
    public function reset(): void
    {
        $this->version = null;
        $this->readAt = 0.0;
        $this->failedUntil = 0.0;
    }
}

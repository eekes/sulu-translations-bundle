<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Recorder;

use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationsImportedEvent;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Psr\Log\LoggerInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;

/**
 * Turns what the code asked for into rows an editor can see.
 *
 * Every `trans()` the catalogue could not answer is buffered and written at the end of the
 * request as a key with source `runtime`. This is what removes `translation:extract` from the
 * loop: a developer adds `{{ 'checkout.total'|trans }}`, someone loads the page, and the key is
 * in the admin.
 *
 * The same pass refreshes `lastUsedAt` on the keys that did resolve, which is the only signal
 * that makes an "unused keys" report trustworthy in a Twig-heavy project.
 */
class MissingMessageRecorder
{
    /**
     * @var array<string, array{domain: string, key: string}>
     */
    private array $missing = [];

    /**
     * @var array<string, string> "domain\0hash" => "domain\0hash"
     */
    private array $used = [];

    private bool $collectMissing;

    private bool $trackUsage;

    /**
     * The two flags are independent on purpose. Recording *new* keys is a decision about
     * whether the site may write to the database on a read request; refreshing `lastUsedAt`
     * on keys that already exist is a decision about whether the unused-keys report is worth
     * anything. A project that wants the second without the first is a perfectly ordinary
     * project, and tying them together made `track_usage: true` silently do nothing.
     */
    public function __construct(
        private readonly TranslationKeySynchronizer $synchronizer,
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        bool $collectMissing,
        private readonly int $limit = 200,
        private readonly int $lastUsedThrottleSeconds = 86400,
        bool $trackUsage = true,
        private readonly ?DomainEventCollectorInterface $domainEventCollector = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ManagedDomains $domains = new ManagedDomains(),
    ) {
        $this->collectMissing = $collectMissing;
        $this->trackUsage = $trackUsage;
    }

    /**
     * Whether the translator needs to tell us anything at all. False means it skips the
     * catalogue lookup that feeds this class entirely.
     */
    public function isEnabled(): bool
    {
        return $this->collectMissing || $this->trackUsage;
    }

    public function isCollectingMissing(): bool
    {
        return $this->collectMissing;
    }

    public function isTrackingUsage(): bool
    {
        return $this->trackUsage;
    }

    public function recordMissing(string $id, string $domain): void
    {
        if (!$this->collectMissing || \count($this->missing) >= $this->limit) {
            return;
        }

        // `admin` by default: that catalogue belongs to Sulu and its bundles, and every label
        // they ship without a translation - `sulu_media.ai_generated` and the rest - would
        // otherwise arrive in the list as work for a translator who has no idea what it is.
        // A row somebody adds there on purpose, to rename one of Sulu's own labels, still
        // works and still gets its `lastUsedAt`.
        if ($this->domains->isIgnored($domain)) {
            return;
        }

        // A loop over a broken key must not be able to write a million rows, and an id that is
        // plainly not a key is not worth a row either.
        if ('' === $id || \strlen($id) > 1000) {
            return;
        }

        $this->missing[$domain . "\0" . $id] = ['domain' => $domain, 'key' => $id];

        // Asked for is asked for. A key that renders as its own id because nobody has
        // translated it yet was still shown on a page, and leaving `lastUsedAt` empty for it
        // would make the unused-keys report flag exactly the keys that need attention most.
        // `flush()` creates the keys before it touches the timestamps, so this lands.
        $this->recordUsed($id, $domain);
    }

    public function recordUsed(string $id, string $domain): void
    {
        if (!$this->trackUsage || \count($this->used) >= $this->limit) {
            return;
        }

        if ('' === $id || \strlen($id) > 1000) {
            return;
        }

        $pair = $domain . "\0" . TranslationKey::hash($id);
        $this->used[$pair] = $pair;
    }

    /**
     * Called on `kernel.terminate` and `console.terminate`: after the response is sent, so the
     * visitor never waits for it, and never inside the request that a failure could break.
     */
    public function flush(): void
    {
        if ([] === $this->missing && [] === $this->used) {
            return;
        }

        $missing = $this->missing;
        $used = $this->used;
        $this->missing = [];
        $this->used = [];

        try {
            $created = 0;

            foreach ($missing as $entry) {
                $this->synchronizer->ensureKey(
                    $entry['domain'],
                    $entry['key'],
                    TranslationSource::Runtime,
                    icu: false,
                    flush: false,
                    collectEvent: false,
                );
                ++$created;
            }

            if ($created > 0) {
                $this->synchronizer->flush();

                $this->domainEventCollector?->collect(new TranslationsImportedEvent([
                    'source' => TranslationSource::Runtime->value,
                    'created' => $created,
                ]));
            }

            if ([] !== $used) {
                $this->keyRepository->touchLastUsed(
                    \array_values($used),
                    new \DateTimeImmutable('@' . (\time() - $this->lastUsedThrottleSeconds)),
                );
            }
        } catch (\Throwable $throwable) {
            // Recording is a convenience. A read-only replica, a closed connection at the end
            // of a request or a missing table must never turn into an error the visitor sees.
            $this->logger?->warning('Discovered translation keys could not be written.', ['exception' => $throwable]);
        }
    }

    public function disable(): void
    {
        $this->collectMissing = false;
        $this->trackUsage = false;
    }

    public function reset(): void
    {
        $this->missing = [];
        $this->used = [];
    }
}

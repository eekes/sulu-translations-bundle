<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Catalogue;

use Symfony\Component\Translation\MessageCatalogueInterface;

/**
 * Adds the database messages to a catalogue and to every catalogue in its fallback chain.
 *
 * It mutates rather than wraps. The obvious alternative - return our own catalogue with the
 * file catalogue as its fallback - is impossible: `MessageCatalogue::addFallbackCatalogue()`
 * throws when both catalogues have the same locale, which is exactly this case. Rebuilding the
 * chain instead would copy every message on every request.
 *
 * `MessageCatalogue::add()` overwrites, which is the rule: the database wins over the files.
 */
class MessageCatalogueEnricher
{
    /**
     * Which catalogue object was enriched at which catalogue version. Keyed by object id, with
     * the object kept alive in the same entry so an id cannot be reused by a new object.
     *
     * @var \SplObjectStorage<MessageCatalogueInterface, string>
     */
    private \SplObjectStorage $enriched;

    public function __construct(
        private readonly CatalogueProvider $catalogueProvider,
        private readonly CatalogueVersionProvider $versionProvider,
    ) {
        $this->enriched = new \SplObjectStorage();
    }

    public function enrich(MessageCatalogueInterface $catalogue): void
    {
        $version = $this->versionProvider->getVersion();

        if (null === $version || '' === $version) {
            return;
        }

        $current = $catalogue;

        while (null !== $current) {
            $this->enrichOne($current, $version);
            $current = $current->getFallbackCatalogue();
        }
    }

    public function reset(): void
    {
        $this->enriched = new \SplObjectStorage();
    }

    private function enrichOne(MessageCatalogueInterface $catalogue, string $version): void
    {
        if (isset($this->enriched[$catalogue]) && $this->enriched[$catalogue] === $version) {
            return;
        }

        foreach ($this->catalogueProvider->getMessages($catalogue->getLocale()) as $domain => $messages) {
            if ([] === $messages) {
                continue;
            }

            $catalogue->add($messages, $domain);
        }

        $this->enriched[$catalogue] = $version;
    }
}

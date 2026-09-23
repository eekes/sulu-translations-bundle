<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

use Symfony\Component\Translation\MessageCatalogue;

/**
 * Separates the ICU messages of a catalogue from the plain ones.
 *
 * **Why this exists.** `MessageCatalogue::all()` with no argument does not give you the raw
 * domains: it merges `messages+intl-icu` *into* `messages` and hands back the stripped name.
 * So the obvious loop -
 *
 * ```php
 * foreach ($catalogue->all() as $domain => $messages) {
 *     $icu = str_ends_with($domain, MessageCatalogue::INTL_DOMAIN_SUFFIX); // always false
 * }
 * ```
 *
 * - silently marks every ICU message as plain. That shipped: a project importing
 * `messages+intl-icu.en.yaml` got its plural messages stored with `icu = 0`, which means the
 * wrong formatter at runtime and no plural editor in the admin.
 *
 * Asking for `all($domain . '+intl-icu')` explicitly *does* return only the ICU messages -
 * that is the one call that skips the merge.
 */
class CatalogueDomainSplitter
{
    /**
     * @return list<array{domain: string, icu: bool, messages: array<string, string>}>
     */
    public function split(MessageCatalogue $catalogue): array
    {
        $groups = [];

        foreach ($catalogue->getDomains() as $domain) {
            /** @var array<string, string> $icuMessages */
            $icuMessages = $catalogue->all($domain . MessageCatalogue::INTL_DOMAIN_SUFFIX);
            /** @var array<string, string> $merged */
            $merged = $catalogue->all($domain);
            $plainMessages = \array_diff_key($merged, $icuMessages);

            if ([] !== $plainMessages) {
                $groups[] = ['domain' => $domain, 'icu' => false, 'messages' => $plainMessages];
            }

            if ([] !== $icuMessages) {
                $groups[] = ['domain' => $domain, 'icu' => true, 'messages' => $icuMessages];
            }
        }

        return $groups;
    }
}

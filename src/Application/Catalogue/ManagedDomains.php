<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Catalogue;

/**
 * The catalogues the bundle is allowed to fill by itself.
 *
 * Every path that creates keys without somebody typing them - runtime discovery, `import`,
 * `scan`, `generate-migration --from=files` - asks this first. The one that made it necessary
 * is `admin`: Sulu and its bundles ship their own labels there, a project that overrides a
 * handful of them keeps a file like `translations/sulu/admin.nl.json`, and Symfony's reader
 * walks a directory recursively. So one `import` used to hand a translator a thousand
 * `sulu_media.*` strings they have no business seeing, from a file that nobody asked to have
 * in the database.
 *
 * Two things this deliberately does **not** do:
 *
 * - It does not stop a write. Adding `admin` / `sulu_media.origin` in the admin on purpose,
 *   to rename one of Sulu's own labels, still works, still resolves at runtime and still goes
 *   out with `generate-migration --from=database`. Refusing a write is `domains.read_only`,
 *   which is a different question with a different answer.
 * - It does not override an explicit `--domain`. Naming the domain on the command line is a
 *   person saying what they want; the list is only what happens when nobody said.
 */
final class ManagedDomains
{
    /**
     * @param list<string> $ignored
     */
    public function __construct(private readonly array $ignored = [])
    {
    }

    public function manages(string $domain): bool
    {
        return !\in_array($domain, $this->ignored, true);
    }

    public function isIgnored(string $domain): bool
    {
        return !$this->manages($domain);
    }

    /**
     * @return list<string>
     */
    public function ignored(): array
    {
        return $this->ignored;
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\Extractor\ExtractorInterface;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Runs Symfony's extractor over the project's templates and PHP, and records where each key
 * occurs.
 *
 * Orphans - keys the extractor no longer finds - are **marked, never deleted**. Twig
 * extensions, keys built from an enum and keys concatenated at runtime are all invisible to a
 * static extractor, so an automatic prune deletes strings that are very much in use. Pruning
 * is a separate, explicit command that also honours `lastUsedAt`.
 */
class CodeScanner
{
    /**
     * A generous ceiling rather than a preview: the panel lists every file, and a key used in
     * twenty places is ordinary. The cap is only here so that one pathological key - a label
     * pasted into hundreds of templates - cannot bloat a JSON column without limit.
     */
    private const MAX_OCCURRENCES = 200;

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly ExtractorInterface $extractor,
        private readonly TranslationKeySynchronizer $synchronizer,
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly array $paths,
        private readonly string $defaultLocale = 'en',
        private readonly string $projectDir = '',
        private readonly ManagedDomains $domains = new ManagedDomains(),
    ) {
    }

    /**
     * The panel shows these to a human, and `/Users/someone/Projects/.../templates/x.twig`
     * is both unreadable and a leak of whichever machine happened to run the scan. Relative
     * to the project root is what a developer recognises and what matches what they would
     * type into their editor.
     */
    private function relativePath(string $path): string
    {
        if ('' === $this->projectDir) {
            return $path;
        }

        $prefix = \rtrim($this->projectDir, '/') . '/';

        return \str_starts_with($path, $prefix) ? \substr($path, \strlen($prefix)) : $path;
    }

    /**
     * @param list<string>|null $paths
     * @param bool $collectLocations keep the per-key file list in the result, for
     *                               a caller that prints it. Off by default: a
     *                               large project holds this twice otherwise, and
     *                               nothing but the report wants it
     *
     * @return array{created: int, seen: int, skipped: int, orphaned: int, unorphaned: int, recorded: int, changed: int, unlocated: int, locations: list<array{domain: string, key: string, occurrences: list<array{path: string, line: int}>}>}
     */
    public function scan(
        ?array $paths = null,
        bool $markOrphans = false,
        bool $dryRun = false,
        bool $collectLocations = false,
    ): array {
        $catalogue = new MessageCatalogue($this->defaultLocale);

        foreach ($paths ?? $this->paths as $path) {
            if (!\is_dir($path) && !\is_file($path)) {
                continue;
            }

            $this->extractor->extract($path, $catalogue);
        }

        $created = 0;
        $seen = 0;
        $skipped = 0;
        $recorded = 0;
        $changed = 0;
        $unlocated = 0;
        $seenHashes = [];
        $locations = [];

        // Symfony records `sources` metadata from the PHP extractor but not from the Twig one,
        // and in a Sulu project most keys live in templates. So the files are walked once more
        // and every quoted literal that matches a key we found is recorded as an occurrence.
        // Without this the "used in" panel is empty for exactly the keys an editor asks about.
        //
        // A dry run walks them too. "Which files is this key in" is the one question a preview
        // of this command is asked, and the walk is cheap next to the extraction that just ran.
        $occurrences = $this->findOccurrences($paths ?? $this->paths, $catalogue);

        foreach ($catalogue->all() as $domain => $messages) {
            $realDomain = \str_ends_with($domain, MessageCatalogue::INTL_DOMAIN_SUFFIX)
                ? \substr($domain, 0, -\strlen(MessageCatalogue::INTL_DOMAIN_SUFFIX))
                : $domain;

            foreach (\array_keys($messages) as $key) {
                $key = (string) $key;
                ++$seen;

                // Recorded as seen before the filter, never after: a key in an ignored domain
                // that somebody added in the admin on purpose is still in use, and dropping it
                // from this list would make --mark-orphans flag it.
                $seenHashes[] = $realDomain . "\0" . TranslationKey::hash($key);

                if ($this->domains->isIgnored($realDomain)) {
                    ++$skipped;

                    continue;
                }

                $found = $this->collectOccurrences($catalogue, $domain, $key, $occurrences);

                if ([] === $found) {
                    // A Twig extension, a key built from an enum, a string the extractor
                    // recognised without being able to point at a line: counted, because an
                    // empty "used in" panel otherwise looks exactly like a scan that never ran.
                    ++$unlocated;
                } else {
                    ++$recorded;

                    if ($collectLocations) {
                        $locations[] = ['domain' => $realDomain, 'key' => $key, 'occurrences' => $found];
                    }
                }

                if ($dryRun) {
                    $existing = $this->keyRepository->findOneByKey($realDomain, $key);

                    if (null === $existing) {
                        ++$created;
                    }

                    if ([] !== $found && !$this->isSameList($existing?->getOccurrences() ?? [], $found)) {
                        ++$changed;
                    }

                    continue;
                }

                $ensured = $this->synchronizer->ensure(
                    $realDomain,
                    $key,
                    TranslationSource::Code,
                    \str_ends_with($domain, MessageCatalogue::INTL_DOMAIN_SUFFIX),
                    flush: true,
                    collectEvent: false,
                );

                if ($ensured->created) {
                    ++$created;
                }

                // Only when it differs, and never when nothing was found: a run narrowed with
                // --path sees part of the project, and wiping the locations a full run wrote
                // would make the panel worse the more often it is used.
                if ([] !== $found && !$this->isSameList($ensured->key->getOccurrences(), $found)) {
                    $ensured->key->setOccurrences($found);
                    $this->keyRepository->save($ensured->key, false);
                    ++$changed;
                }
            }
        }

        $orphaned = 0;
        $unorphaned = 0;

        if ($markOrphans && !$dryRun) {
            [$orphaned, $unorphaned] = $this->markOrphans($seenHashes);
        }

        if (!$dryRun) {
            // `ensure()` flushes per key, which means every occurrence write rides along with
            // the *next* key - and the last one never gets written at all. One flush at the
            // end, always, not only when orphan marking happens to run.
            $this->synchronizer->flush();
        }

        return [
            'created' => $created,
            'seen' => $seen,
            'skipped' => $skipped,
            'orphaned' => $orphaned,
            'unorphaned' => $unorphaned,
            'recorded' => $recorded,
            'changed' => $changed,
            'unlocated' => $unlocated,
            'locations' => $locations,
        ];
    }

    /**
     * @param list<string> $seenHashes
     *
     * @return array{0: int, 1: int}
     */
    private function markOrphans(array $seenHashes): array
    {
        $seen = \array_flip($seenHashes);
        $orphaned = 0;
        $unorphaned = 0;
        $now = new \DateTimeImmutable();

        // Runtime discovery is a second opinion the extractor does not have: a key nothing
        // renders any more but that was asked for yesterday is not an orphan.
        $recentlyUsed = $now->modify('-30 days');

        foreach ($this->keyRepository->findUnused($seenHashes, $recentlyUsed) as $key) {
            if ($key->isOrphaned()) {
                continue;
            }

            $key->setOrphanedAt($now);
            $this->keyRepository->save($key, false);
            ++$orphaned;
        }

        foreach ($this->keyRepository->findOrphanedBefore($now->modify('+1 second'), true) as $key) {
            if (!isset($seen[$key->getDomain() . "\0" . $key->getKeyHash()])) {
                continue;
            }

            $key->setOrphanedAt(null);
            $this->keyRepository->save($key, false);
            ++$unorphaned;
        }

        $this->synchronizer->flush();

        return [$orphaned, $unorphaned];
    }

    /**
     * Collects every file the key occurs in, from **both** sources. It writes nothing - the
     * caller compares the result with what the key already holds, so a scan that found the
     * same thing again is not a write.
     *
     * Symfony's PHP extractor reports `sources` metadata; its Twig extractor reports none.
     * Taking the metadata when it exists and the literal scan otherwise looks reasonable and
     * is wrong: a key used in a controller *and* in three templates then lists only the
     * controller, because the PHP side happened to answer first. They are merged.
     *
     * @param array<string, list<array{path: string, line: int}>> $scanned
     *
     * @return list<array{path: string, line: int}>
     */
    private function collectOccurrences(
        MessageCatalogue $catalogue,
        string $domain,
        string $key,
        array $scanned,
    ): array {
        /** @var array{sources?: list<string>}|null $metadata */
        $metadata = $catalogue->getMetadata($key, $domain);

        $occurrences = [];

        foreach ($metadata['sources'] ?? [] as $source) {
            $position = \strrpos($source, ':');

            if (false === $position) {
                $occurrences[] = ['path' => $source, 'line' => 0];

                continue;
            }

            $occurrences[] = [
                'path' => \substr($source, 0, $position),
                'line' => (int) \substr($source, $position + 1),
            ];
        }

        foreach ($scanned[$key] ?? [] as $occurrence) {
            $occurrences[] = $occurrence;
        }

        return [] === $occurrences ? [] : $this->deduplicate($occurrences);
    }

    /**
     * Whether the key already holds exactly these locations.
     *
     * Compared on `path:line` rather than with `===`, because MySQL normalises the key order
     * inside a JSON object: the scanner writes `{path, line}` and reads back `{line, path}`.
     * A strict comparison calls that a change, and every scan then rewrites every row it
     * looked at and reports a table full of changes that are not changes.
     *
     * @param list<array{path: string, line: int}> $stored
     * @param list<array{path: string, line: int}> $found
     */
    private function isSameList(array $stored, array $found): bool
    {
        $flatten = static fn (array $occurrences): array => \array_map(
            static fn (array $occurrence): string => $occurrence['path'] . ':' . $occurrence['line'],
            $occurrences,
        );

        return $flatten($stored) === $flatten($found);
    }

    /**
     * The two sources overlap for PHP files, which the extractor and the literal scan both
     * see.
     *
     * @param list<array{path: string, line: int}> $occurrences
     *
     * @return list<array{path: string, line: int}>
     */
    private function deduplicate(array $occurrences): array
    {
        $unique = [];

        foreach ($occurrences as $occurrence) {
            $unique[$occurrence['path'] . ':' . $occurrence['line']] = $occurrence;
        }

        $unique = \array_map(
            fn (array $occurrence): array => ['path' => $this->relativePath($occurrence['path']), 'line' => $occurrence['line']],
            \array_values($unique),
        );

        \usort($unique, static function (array $left, array $right): int {
            return [$left['path'], $left['line']] <=> [$right['path'], $right['line']];
        });

        return \array_slice($unique, 0, self::MAX_OCCURRENCES);
    }

    /**
     * One pass per file: every quoted literal on a line is looked up in the set of keys the
     * extractor found. A hash lookup per literal rather than a search per key, so the cost is
     * the size of the codebase and not the size of the codebase times the number of keys.
     *
     * @param list<string> $paths
     *
     * @return array<string, list<array{path: string, line: int}>>
     */
    private function findOccurrences(array $paths, MessageCatalogue $catalogue): array
    {
        $wanted = [];

        foreach ($catalogue->all() as $messages) {
            foreach (\array_keys($messages) as $key) {
                $wanted[(string) $key] = true;
            }
        }

        if ([] === $wanted) {
            return [];
        }

        $directories = \array_values(\array_filter($paths, '\is_dir'));
        $occurrences = [];

        if ([] !== $directories) {
            $finder = (new Finder())
                ->files()
                ->in($directories)
                ->name(['*.php', '*.twig'])
                ->ignoreUnreadableDirs();

            foreach ($finder as $file) {
                $this->collectFromFile($file->getPathname(), $wanted, $occurrences);
            }
        }

        foreach ($paths as $path) {
            if (\is_file($path)) {
                $this->collectFromFile($path, $wanted, $occurrences);
            }
        }

        return $occurrences;
    }

    /**
     * @param array<string, true> $wanted
     * @param array<string, list<array{path: string, line: int}>> $occurrences
     */
    private function collectFromFile(string $path, array $wanted, array &$occurrences): void
    {
        $contents = @\file_get_contents($path);

        if (false === $contents) {
            return;
        }

        foreach (\explode("\n", $contents) as $index => $line) {
            if (!\preg_match_all('/([\'"])((?:[^\'"\\\\]|\\\\.)*?)\\1/', $line, $matches)) {
                continue;
            }

            foreach ($matches[2] as $literal) {
                if (!isset($wanted[$literal]) || \count($occurrences[$literal] ?? []) >= self::MAX_OCCURRENCES) {
                    continue;
                }

                $occurrences[$literal][] = ['path' => $path, 'line' => $index + 1];
            }
        }
    }
}

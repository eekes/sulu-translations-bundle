<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

use Eekes\SuluTranslationsBundle\Application\Catalogue\ManagedDomains;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Domain\Model\CatalogueState;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Reader\TranslationReaderInterface;

/**
 * Turns translations into a Doctrine migration, so a release carries them to every
 * environment and nobody has to run anything by hand after a deploy.
 *
 * It reads from either of two places, and which one you want depends on where you are:
 *
 * - **`files`** is the on-ramp. Somebody with `messages.nl.xlf`, `validators.fr.yaml` and a
 *   handful of other files installs the bundle and wants all of it in the database on every
 *   environment. Reading through Symfony's own `translation.reader` means every format
 *   Symfony supports is supported here: XLIFF, YAML, CSV, JSON, PHP, .po.
 * - **`database`** is what you use ever after. A developer adds twenty `trans()` calls, the
 *   keys appear in their own admin by themselves, they fill the four languages there, and
 *   this turns exactly those rows into the next migration. Nothing is typed twice and nothing
 *   waits for the deploy.
 *
 * The database source ships a **delta**: the values no migration has carried yet, which is
 * `migrated_at IS NULL`. Without that every release would re-emit the whole catalogue - tens
 * of thousands of statements nobody can review. The generated SQL sets `migrated_at` on the
 * rows it carries, so generating has no side effect and the mark lands on every machine that
 * runs the migration, including the one that generated it.
 *
 * Four properties the generated SQL must have, in order of how much they hurt when missing:
 *
 * 1. **It may never overwrite a value that already has content.** By the time this runs on
 *    production somebody may have edited that string in the admin. Every insert is guarded by
 *    a `NOT EXISTS`, which also makes the migration safe to re-run: a row that is already
 *    there for whatever reason is simply left alone.
 * 2. **It must be portable.** `INSERT ... SELECT ... FROM (SELECT 1) AS seed WHERE NOT EXISTS`
 *    is the one shape that behaves the same on MySQL, PostgreSQL and SQLite; `INSERT IGNORE`
 *    and `ON CONFLICT` do not.
 * 3. **It must not bake in ids.** Auto-increment ids on the target have nothing to do with
 *    the generating machine, so every value row looks its key up by `(domain, key_hash)`.
 * 4. **It must be parameterised.** A translation is arbitrary text, and a generator that
 *    concatenates SQL works until the first value containing a quote, in production, during
 *    a deploy.
 */
class MigrationGenerator
{
    public const SOURCE_FILES = 'files';

    public const SOURCE_DATABASE = 'database';

    /**
     * The deletions of a `prune`, which is the only cleanup that needs carrying.
     *
     * `scan --mark-orphans` records a local observation and `sync-locales` repairs itself on
     * the first request, but a key deleted here stays in every other database for good. The
     * statements this produces are the mirror image of the inserts and the one place in this
     * file where a target row is destroyed, so they carry guards of their own - see
     * `deleteKey()`.
     */
    public const SOURCE_DELETIONS = 'deletions';

    /**
     * How many key hashes go into one `IN (...)` list when marking rows as shipped.
     *
     * Marking per row would double the statement count of a bootstrap migration for no gain;
     * one statement per (domain, locale) would build an `IN` list of every key in the project.
     */
    private const MARK_CHUNK = 500;

    /**
     * @param list<string> $defaultPaths
     */
    public function __construct(
        private readonly TranslationReaderInterface $reader,
        private readonly CatalogueDomainSplitter $splitter,
        private readonly LocaleProvider $localeProvider,
        private readonly Filesystem $filesystem,
        private readonly TranslationRepositoryInterface $repository,
        private readonly array $defaultPaths,
        private readonly string $defaultDirectory,
        private readonly string $defaultNamespace,
        private readonly ManagedDomains $domains = new ManagedDomains(),
    ) {
    }

    /**
     * The translation files of the project, all of them.
     *
     * @param list<string>|null $paths
     * @param list<string>|null $locales
     *
     * @return array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>}
     */
    public function generate(?array $paths = null, ?array $locales = null, ?string $domain = null): array
    {
        $paths ??= $this->defaultPaths;
        $locales ??= $this->localeProvider->getLocales();

        [$keys, $values, $skipped] = $this->read($paths, $locales, $domain);

        return $this->build(self::SOURCE_FILES, $keys, $values, $locales, [], [], $skipped);
    }

    /**
     * The rows somebody filled in locally, and by default only the ones no release has taken
     * along yet.
     *
     * A key enters the migration when any of its values is new; once it does it is carried
     * **completely**, every locale it has. Shipping half a key would leave a freshly cloned
     * staging database with the Dutch of a key whose English went out three releases ago, and
     * the statements for the values that were already shipped are guarded no-ops everywhere
     * that has them.
     *
     * @param list<string>|null $locales
     * @param bool $delta false carries every non-empty value, shipped or not
     * @param bool $includeChanged also carry the values that were edited after they were
     *                             shipped - an unconditional overwrite of whatever the target
     *                             has, so it is opt-in and never the default
     *
     * @return array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>}
     */
    public function generateFromDatabase(
        ?array $locales = null,
        ?string $domain = null,
        bool $delta = true,
        bool $includeChanged = false,
    ): array {
        $locales ??= $this->localeProvider->getLocales();

        $rows = $this->repository->findForMigration($locales, $domain);

        $keys = [];
        $values = [];
        $carried = [];
        $changed = [];
        $overwrites = [];

        foreach ($rows as $row) {
            $identity = $row['domain'] . "\0" . $row['hash'];

            $keys[$identity] = [
                'domain' => $row['domain'],
                'key' => $row['key'],
                'hash' => $row['hash'],
                'icu' => $row['icu'],
            ];
            $values[$identity . "\0" . $row['locale']] = $row['value'];

            if (!$delta || !$row['shipped']) {
                $carried[$identity] = true;

                continue;
            }

            if (!$row['changedSinceShipped']) {
                continue;
            }

            $changed[] = ['domain' => $row['domain'], 'key' => $row['key'], 'locale' => $row['locale']];

            if ($includeChanged) {
                $carried[$identity] = true;
                $overwrites[] = ['key' => $keys[$identity], 'locale' => $row['locale'], 'value' => $row['value']];
            }
        }

        $keys = \array_intersect_key($keys, $carried);
        \ksort($keys);

        // No domain filter on this side: a key in an ignored domain only exists here because
        // somebody created it in the admin on purpose, and a release has to carry it like any
        // other row - otherwise the override works locally and nowhere else.
        return $this->build(self::SOURCE_DATABASE, $keys, $values, $locales, $overwrites, $changed);
    }

    /**
     * The keys a `prune` has just deleted here, as the SQL that deletes them everywhere else.
     *
     * The mirror image of the insert side, and the opposite risk: an insert that is wrong is
     * a no-op, a delete that is wrong destroys the translations and the history of a string
     * somebody is still using. Three things keep that from happening:
     *
     * 1. **The target decides.** Every statement carries `last_used_at`, so a key this
     *    machine has not seen in months but production rendered this morning survives there.
     *    The one signal that matters is the one the target has and the generator does not.
     * 2. **Protected stays protected**, unless the prune that produced this deliberately
     *    included protected keys.
     * 3. **It is not final.** A key that turns out to be in use after all reappears on the
     *    next render with source `runtime`; what is lost is its values and its versions.
     *
     * The value and version rows go with the key through the `ON DELETE CASCADE` on their
     * foreign keys, which is why this is one statement per key and not three - and why the
     * guard only has to be written once.
     *
     * @param list<TranslationKey> $keys the keys as they were before they were deleted
     * @param \DateTimeImmutable $unusedSince a key the target rendered after this is kept
     * @param bool $includeProtected drop the protected guard, for a prune that was run with
     *                               `--include-protected`
     *
     * @return array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>}
     */
    public function generateDeletions(array $keys, \DateTimeImmutable $unusedSince, bool $includeProtected = false): array
    {
        $statements = [];

        foreach ($keys as $key) {
            $statements[] = $this->deleteKey($key->getDomain(), $key->getKeyHash(), $unusedSince, $includeProtected);
        }

        if ([] !== $statements) {
            $statements = [...$statements, ...$this->bumpVersion((new \DateTimeImmutable())->format('Y-m-d H:i:s'))];
        }

        return [
            'source' => self::SOURCE_DELETIONS,
            'statements' => $statements,
            'keys' => \count($keys),
            'values' => 0,
            'locales' => [],
            'changed' => [],
            'skipped' => [],
        ];
    }

    /**
     * @param array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>} $result
     *
     * @return array{path: string, class: string}
     */
    public function write(array $result, ?string $directory = null, ?string $namespace = null): array
    {
        $directory ??= $this->defaultDirectory;
        $namespace ??= $this->defaultNamespace;

        $class = 'Version' . (new \DateTimeImmutable())->format('YmdHis');
        $path = \rtrim($directory, '/') . '/' . $class . '.php';

        $this->filesystem->mkdir($directory);
        $this->filesystem->dumpFile($path, $this->render($class, $namespace, $result));

        return [
            'path' => $path,
            'class' => $namespace . '\\' . $class,
        ];
    }

    /**
     * @param array<string, array{domain: string, key: string, hash: string, icu: bool}> $keys
     * @param array<string, string|null> $values
     * @param list<string> $locales
     * @param list<array{key: array{domain: string, key: string, hash: string, icu: bool}, locale: string, value: string}> $overwrites
     * @param list<array{domain: string, key: string, locale: string}> $changed
     * @param list<string> $skipped domains left out because of domains.ignore
     *
     * @return array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>}
     */
    private function build(string $source, array $keys, array $values, array $locales, array $overwrites, array $changed = [], array $skipped = []): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $statements = [];

        foreach ($keys as $key) {
            $statements[] = $this->insertKey($key, $now);
        }

        // Every key gets a row in every language, empty ones included. That materialisation
        // is what makes the admin list work at all - a key with nothing in French has to be
        // a French row, or the "not translated" screen cannot show it.
        $valueCount = 0;
        $marks = [];

        foreach ($keys as $key) {
            foreach ($locales as $locale) {
                $value = $values[$key['domain'] . "\0" . $key['hash'] . "\0" . $locale] ?? null;
                $statements[] = $this->insertValue($key, $locale, $value, $now);

                // The insert above only fires when the row is missing. A release that adds a
                // Dutch file to a project that already ran an earlier migration finds the
                // Dutch row there and empty, so filling it needs its own statement.
                if (null !== $value && '' !== $value) {
                    $statements[] = $this->fillEmptyValue($key, $locale, $value, $now);
                }

                $marks[$key['domain']][$locale][] = $key['hash'];
                ++$valueCount;
            }
        }

        foreach ($overwrites as $overwrite) {
            $statements[] = $this->overwriteValue($overwrite['key'], $overwrite['locale'], $overwrite['value'], $now);
        }

        // On every environment but the one that generated this, the inserts above already
        // carry `migrated_at`. Here it is the generating machine that matters: its rows are
        // the source of the data, they exist, so nothing above touched them, and without
        // this they would be handed to the next release all over again.
        foreach ($marks as $domain => $byLocale) {
            foreach ($byLocale as $locale => $hashes) {
                foreach (\array_chunk(\array_values(\array_unique($hashes)), self::MARK_CHUNK) as $chunk) {
                    $statements[] = $this->markMigrated((string) $domain, (string) $locale, $chunk, $now);
                }
            }
        }

        $statements = [...$statements, ...$this->bumpVersion($now)];

        return [
            'source' => $source,
            'statements' => $statements,
            'keys' => \count($keys),
            'values' => $valueCount,
            'locales' => $locales,
            'changed' => $changed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Without this the target keeps serving its cached catalogue: the rows changed and
     * nothing told the version counter, so no request has any reason to look again. It is as
     * true of a migration that deletes as of one that loads.
     *
     * @return list<MigrationStatement>
     */
    private function bumpVersion(string $now): array
    {
        return [
            new MigrationStatement(
                'INSERT INTO tl_translation_state (id, version, token, updated_at)'
                . ' SELECT ?, ?, ?, ? FROM (SELECT 1) AS seed'
                . ' WHERE NOT EXISTS (SELECT 1 FROM tl_translation_state WHERE id = ?)',
                [CatalogueState::ID, 1, CatalogueState::generateToken(), $now, CatalogueState::ID],
            ),
            new MigrationStatement(
                'UPDATE tl_translation_state SET version = version + 1, updated_at = ? WHERE id = ?',
                [$now, CatalogueState::ID],
            ),
        ];
    }

    /**
     * Removes a key from the target, if the target agrees that it is gone.
     *
     * Deliberately *not* guarded on `orphaned_at`: the extractor runs on a developer machine
     * and in CI, so the column is usually null on production and a guard on it would make
     * every one of these a silent no-op. `last_used_at` is the column the target fills by
     * itself, which is the whole point of asking it.
     *
     * One caveat worth knowing: a project that turned runtime discovery off has no
     * `last_used_at` anywhere, and then this deletes whatever the prune decided. That is the
     * same evidence the prune ran on, so it is consistent rather than surprising - but it is
     * one guard less.
     */
    private function deleteKey(string $domain, string $hash, \DateTimeImmutable $unusedSince, bool $includeProtected): MigrationStatement
    {
        $sql = 'DELETE FROM tl_translation_key'
            . ' WHERE domain = ? AND key_hash = ?'
            . ' AND (last_used_at IS NULL OR last_used_at < ?)';

        if (!$includeProtected) {
            $sql .= ' AND is_protected = 0';
        }

        return new MigrationStatement($sql, [$domain, $hash, $unusedSince->format('Y-m-d H:i:s')]);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $locales
     *
     * @return array{0: array<string, array{domain: string, key: string, hash: string, icu: bool}>, 1: array<string, string>, 2: list<string>}
     */
    private function read(array $paths, array $locales, ?string $domain): array
    {
        $keys = [];
        $values = [];
        $skipped = [];

        foreach ($locales as $locale) {
            $catalogue = new MessageCatalogue($locale);

            foreach ($paths as $path) {
                if (!\is_dir($path)) {
                    continue;
                }

                $this->reader->read($path, $catalogue);
            }

            foreach ($this->splitter->split($catalogue) as $group) {
                if (null !== $domain) {
                    if ($group['domain'] !== $domain) {
                        continue;
                    }
                } elseif ($this->domains->isIgnored($group['domain'])) {
                    // Same rule as the import: a migration carries what the project owns, and
                    // `translations/sulu/admin.nl.json` is Sulu's catalogue with a few labels
                    // renamed, not a thousand strings a translator is supposed to see. Ask for
                    // it by name with --domain and it is carried.
                    $skipped[$group['domain']] = $group['domain'];

                    continue;
                }

                foreach ($group['messages'] as $key => $value) {
                    $key = (string) $key;
                    $hash = TranslationKey::hash($key);
                    $identity = $group['domain'] . "\0" . $hash;

                    $keys[$identity] = [
                        'domain' => $group['domain'],
                        'key' => $key,
                        'hash' => $hash,
                        'icu' => $group['icu'],
                    ];
                    $values[$identity . "\0" . $locale] = (string) $value;
                }
            }
        }

        \ksort($keys);

        return [$keys, $values, \array_values($skipped)];
    }

    /**
     * @param array{domain: string, key: string, hash: string, icu: bool} $key
     */
    private function insertKey(array $key, string $now): MigrationStatement
    {
        return new MigrationStatement(
            'INSERT INTO tl_translation_key (domain, translation_key, key_hash, icu, is_protected, source, created_at)'
            . ' SELECT ?, ?, ?, ?, ?, ?, ? FROM (SELECT 1) AS seed'
            . ' WHERE NOT EXISTS (SELECT 1 FROM tl_translation_key WHERE domain = ? AND key_hash = ?)',
            [
                $key['domain'],
                $key['key'],
                $key['hash'],
                $key['icu'] ? 1 : 0,
                0,
                TranslationSource::File->value,
                $now,
                $key['domain'],
                $key['hash'],
            ],
        );
    }

    /**
     * @param array{domain: string, key: string, hash: string, icu: bool} $key
     */
    private function insertValue(array $key, string $locale, ?string $value, string $now): MigrationStatement
    {
        $value = ('' === $value) ? null : $value;

        return new MigrationStatement(
            'INSERT INTO tl_translation (translation_key_id, locale, value, is_translated, created, changed, migrated_at)'
            . ' SELECT k.id, ?, ?, ?, ?, ?, ? FROM tl_translation_key k'
            . ' WHERE k.domain = ? AND k.key_hash = ?'
            . ' AND NOT EXISTS (SELECT 1 FROM tl_translation t WHERE t.translation_key_id = k.id AND t.locale = ?)',
            [
                $locale,
                $value,
                null === $value ? 0 : 1,
                $now,
                $now,
                null === $value ? null : $now,
                $key['domain'],
                $key['hash'],
                $locale,
            ],
        );
    }

    /**
     * Fills a row that is already there and still empty.
     *
     * Deliberately `value IS NULL OR value = ''` and nothing else: a row with content was
     * written by a person and is never touched. Portable on purpose - `UPDATE ... JOIN` is
     * MySQL-only, a subselect against a *different* table is not.
     *
     * @param array{domain: string, key: string, hash: string, icu: bool} $key
     */
    private function fillEmptyValue(array $key, string $locale, string $value, string $now): MigrationStatement
    {
        return new MigrationStatement(
            'UPDATE tl_translation SET value = ?, is_translated = 1, changed = ?, migrated_at = ?'
            . ' WHERE locale = ? AND (value IS NULL OR value = \'\')'
            . ' AND translation_key_id IN (SELECT id FROM tl_translation_key WHERE domain = ? AND key_hash = ?)',
            [$value, $now, $now, $locale, $key['domain'], $key['hash']],
        );
    }

    /**
     * Replaces a value that already has content - the one statement in this file that can
     * destroy somebody's work.
     *
     * Only `--include-changed` produces it, never the default run, because there is no way to
     * tell from here whether the target's value is the one this migration was generated
     * against or something an editor typed this morning.
     *
     * @param array{domain: string, key: string, hash: string, icu: bool} $key
     */
    private function overwriteValue(array $key, string $locale, string $value, string $now): MigrationStatement
    {
        return new MigrationStatement(
            'UPDATE tl_translation SET value = ?, is_translated = 1, changed = ?, migrated_at = ?'
            . ' WHERE locale = ?'
            . ' AND translation_key_id IN (SELECT id FROM tl_translation_key WHERE domain = ? AND key_hash = ?)',
            [$value, $now, $now, $locale, $key['domain'], $key['hash']],
        );
    }

    /**
     * @param list<string> $hashes
     */
    private function markMigrated(string $domain, string $locale, array $hashes, string $now): MigrationStatement
    {
        $placeholders = \implode(', ', \array_fill(0, \count($hashes), '?'));

        return new MigrationStatement(
            'UPDATE tl_translation SET migrated_at = ?'
            . ' WHERE locale = ?'
            . ' AND translation_key_id IN (SELECT id FROM tl_translation_key WHERE domain = ? AND key_hash IN (' . $placeholders . '))',
            [$now, $locale, $domain, ...$hashes],
        );
    }

    /**
     * @param array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>} $result
     */
    private function render(string $class, string $namespace, array $result): string
    {
        $body = '';

        foreach ($result['statements'] as $statement) {
            $body .= \sprintf(
                "        \$this->addSql(\n            %s,\n            %s,\n        );\n\n",
                \var_export($statement->sql, true),
                $this->exportParameters($statement->parameters),
            );
        }

        $deletions = self::SOURCE_DELETIONS === $result['source'];

        // `{{ description }}` and `{{ irreversible }}` are substituted into single-quoted
        // strings in the generated class, so neither may contain an apostrophe. "the
        // project's translation files" shipped once and produced a parse error in every
        // migration it wrote.
        return \str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ summary }}', '{{ command }}', '{{ generated }}', '{{ notice }}', '{{ description }}', '{{ irreversible }}', '{{ body }}'],
            [
                $namespace,
                $class,
                $this->summary($result),
                $deletions ? 'eekes:translations:prune --migration' : 'eekes:translations:generate-migration',
                (new \DateTimeImmutable())->format('Y-m-d H:i'),
                $this->notice($deletions),
                $deletions
                    ? \sprintf('Delete %d translation keys that were pruned', $result['keys'])
                    : \sprintf('Load %d translation keys from %s', $result['keys'], $this->sourceLabel($result['source'])),
                $deletions
                    ? 'Rolling this back cannot bring deleted translations back.'
                    : 'Rolling this back would delete translations that editors may have changed since.',
                \rtrim($body),
            ],
            $this->template(),
        );
    }

    /**
     * @param array{source: string, statements: list<MigrationStatement>, keys: int, values: int, locales: list<string>, changed: list<array{domain: string, key: string, locale: string}>, skipped: list<string>} $result
     */
    private function summary(array $result): string
    {
        if (self::SOURCE_DELETIONS === $result['source']) {
            return \sprintf('%d translation key(s) deleted by a prune, and everything that hung off them.', $result['keys']);
        }

        return \sprintf(
            '%d key(s) and %d value row(s) from %s, in %s.',
            $result['keys'],
            $result['values'],
            $this->sourceLabel($result['source']),
            \implode(', ', $result['locales']),
        );
    }

    private function sourceLabel(string $source): string
    {
        return self::SOURCE_DATABASE === $source ? 'the database' : 'the translation files';
    }

    /**
     * The paragraph that tells whoever opens the file in three months what it is allowed to
     * do to their data. Rendered as docblock lines, so it replaces a whole line of the
     * template rather than sitting inside one.
     */
    private function notice(bool $deletions): string
    {
        $lines = $deletions
            ? [
                'Every statement is guarded, so this migration is safe to re-run and will **never',
                'delete a key the target is still rendering** - a string this machine had not seen',
                'in months may be in daily use on production, and only production knows that.',
                'Protected keys are left alone as well.',
                '',
                'It is also less final than it looks: a key that turns out to be in use reappears',
                'by itself on the next render, empty, with source "runtime". What a wrong delete',
                'costs is the translations and the version history of that key, not the site.',
            ]
            : [
                'Every statement is guarded, so this migration is safe to re-run and will **never',
                'overwrite a value that already has content** - by the time it reaches production',
                'somebody may have edited that text in the admin. New keys and empty values are',
                'filled in; everything else is left exactly as it is.',
            ];

        return \implode("\n", \array_map(
            static fn (string $line): string => '' === $line ? ' *' : ' * ' . $line,
            $lines,
        ));
    }

    /**
     * @param list<mixed> $parameters
     */
    private function exportParameters(array $parameters): string
    {
        return '[' . \implode(', ', \array_map(
            static fn ($parameter): string => \var_export($parameter, true),
            $parameters,
        )) . ']';
    }

    private function template(): string
    {
        return <<<'PHP_TEMPLATE'
            <?php

            declare(strict_types=1);

            namespace {{ namespace }};

            use Doctrine\DBAL\Schema\Schema;
            use Doctrine\Migrations\AbstractMigration;

            /**
             * {{ summary }}
             *
             * Generated on {{ generated }} by `{{ command }}`.
             *
            {{ notice }}
             */
            final class {{ class }} extends AbstractMigration
            {
                public function getDescription(): string
                {
                    return '{{ description }}';
                }

                public function up(Schema $schema): void
                {
                    // A deploy that reaches this before the schema migration - or a checkout
                    // where the bundle was removed again - must not die halfway through a
                    // release over a table that is not there.
                    $this->skipIf(
                        !$schema->hasTable('tl_translation_key') || !$schema->hasTable('tl_translation'),
                        'The translation tables do not exist yet; run the schema migration first.'
                    );

            {{ body }}
                }

                public function down(Schema $schema): void
                {
                    $this->throwIrreversibleMigrationException(
                        '{{ irreversible }}'
                    );
                }
            }

            PHP_TEMPLATE;
    }
}

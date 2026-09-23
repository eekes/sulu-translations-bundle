<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\MigrationGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the migration that carries translations to the other environments.
 *
 * Two jobs, one command, picked with `--from`:
 *
 * - `--from=files` is the install. A project that already has `messages.nl.xlf` and friends
 *   runs it once and every environment gets the lot on its next `doctrine:migrations:migrate`.
 * - `--from=database` is every release after that. The keys a developer added appeared in
 *   their own admin by themselves and they filled the languages there; this takes exactly
 *   those rows - the ones no migration has carried yet - and nothing else.
 *
 * Deliberately not `import`, which does the same reading but writes straight into the
 * database it is pointed at. That is the right tool while developing and the wrong one for a
 * deploy, where the files may not even be present and nobody is going to run a command on
 * each environment by hand.
 */
#[AsCommand(
    name: 'eekes:translations:generate-migration',
    description: 'Write a Doctrine migration that carries translations to every environment.',
)]
class GenerateMigrationCommand extends Command
{
    public function __construct(private readonly MigrationGenerator $generator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Where the translations come from: "files" (the install) or "database" (every release after it).', MigrationGenerator::SOURCE_FILES)
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directory with translation files, repeatable. Defaults to the configured import paths. Only with --from=files.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these languages. Defaults to every language the webspaces declare.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only this domain.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'With --from=database: carry every value, not only the ones no migration has carried yet.')
            ->addOption('include-changed', null, InputOption::VALUE_NONE, 'With --from=database: also carry values edited since they were shipped. This OVERWRITES whatever the target has.')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Where to write. Defaults to the configured migrations directory.')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace of the generated class.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what it would contain and write no file. Without it the report is still shown first and the file is only written once you confirm.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $source */
        $source = $input->getOption('from');

        if (!\in_array($source, [MigrationGenerator::SOURCE_FILES, MigrationGenerator::SOURCE_DATABASE], true)) {
            $io->error(\sprintf('Unknown source "%s". Use --from=files or --from=database.', $source));

            return Command::INVALID;
        }

        /** @var list<string> $paths */
        $paths = $input->getOption('path');
        /** @var list<string> $locales */
        $locales = $input->getOption('locale');
        /** @var string|null $domain */
        $domain = $input->getOption('domain');

        $includeChanged = (bool) $input->getOption('include-changed');

        $result = MigrationGenerator::SOURCE_DATABASE === $source
            ? $this->generator->generateFromDatabase(
                [] === $locales ? null : $locales,
                $domain,
                delta: !(bool) $input->getOption('all'),
                includeChanged: $includeChanged,
            )
            : $this->generator->generate(
                [] === $paths ? null : $paths,
                [] === $locales ? null : $locales,
                $domain,
            );

        $io->table(['', 'count'], [
            ['source', MigrationGenerator::SOURCE_DATABASE === $source ? 'the database' : 'the translation files'],
            ['keys', (string) $result['keys']],
            ['value rows', (string) $result['values']],
            ['languages', \implode(', ', $result['locales'])],
            ['SQL statements', (string) \count($result['statements'])],
        ]);

        $this->reportChanged($io, $result['changed'], $includeChanged);

        if ([] !== $result['skipped']) {
            $io->note(\sprintf(
                'Left out: %s. Those catalogues are listed in eekes_sulu_translations.domains.ignore, so a migration never carries them by itself - what a project keeps in `translations/sulu/admin.*.json` is Sulu\'s catalogue, not its own. Pass --domain=%s to carry one anyway.',
                \implode(', ', $result['skipped']),
                $result['skipped'][0],
            ));
        }

        if (0 === $result['keys']) {
            if ([] !== $result['skipped']) {
                $io->warning('Everything that was found is in an ignored domain, so there is nothing to carry.');

                return Command::SUCCESS;
            }

            $io->warning(MigrationGenerator::SOURCE_DATABASE === $source
                ? 'Nothing to carry. Every value in the database has already gone out with an earlier migration, or there are none yet. Use --all to write one anyway.'
                : 'No translation files were found. Check --path, or the eekes_sulu_translations.import.paths setting.');

            return Command::SUCCESS;
        }

        if ((bool) $input->getOption('dry-run')) {
            $io->note('Nothing was written. Run again without --dry-run to create the migration.');

            return Command::SUCCESS;
        }

        // The numbers above are the whole point of asking: what ends up in the file is decided
        // by a config setting, a delta and a --domain, and the moment they are wrong is the
        // moment before a file gets committed - not after somebody reviews a migration with a
        // thousand rows of somebody else's catalogue in it. `--no-interaction` answers yes, so
        // a deploy script or a CI job is unaffected.
        if (!$io->confirm('Write this migration?', true)) {
            $io->note('Nothing was written.');

            return Command::SUCCESS;
        }

        /** @var string|null $directory */
        $directory = $input->getOption('migrations-dir');
        /** @var string|null $namespace */
        $namespace = $input->getOption('namespace');

        $written = $this->generator->write($result, $directory, $namespace);

        $io->success(\sprintf(
            'Wrote %s: %d keys and %d value rows for %s.',
            $written['path'],
            $result['keys'],
            $result['values'],
            \implode(', ', $result['locales']),
        ));

        $io->text([
            'Commit it. Every environment picks the translations up on its next migration run:',
            '',
            '    <info>bin/console doctrine:migrations:migrate</info>',
            '',
            'It is safe to re-run and will never overwrite a value somebody has edited in the admin.',
        ]);

        if (MigrationGenerator::SOURCE_DATABASE === $source) {
            // The rows are marked as shipped by the migration, not by this command, so a
            // generated file that is thrown away costs nothing. The flip side is that the
            // mark only lands once the migration has actually run here too.
            $io->note('Run the migration locally as well: it is what marks these values as shipped, so the next release does not carry them again.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<array{domain: string, key: string, locale: string}> $changed
     */
    private function reportChanged(SymfonyStyle $io, array $changed, bool $includeChanged): void
    {
        if ([] === $changed) {
            return;
        }

        $lines = [];

        foreach ($changed as $entry) {
            $lines[] = \sprintf('%s / %s  [%s]', $entry['domain'], $entry['key'], $entry['locale']);
        }

        if ($includeChanged) {
            $io->warning(\array_merge(
                [\sprintf('Carrying %d value(s) that were edited after they were shipped. These OVERWRITE whatever the target has, including an editor\'s work:', \count($changed))],
                $lines,
            ));

            return;
        }

        $io->note(\array_merge(
            [\sprintf('Skipped %d value(s) that were edited after they were shipped, because a release cannot tell them apart from an editor\'s change on production. Use --include-changed to carry them anyway:', \count($changed))],
            $lines,
        ));
    }
}

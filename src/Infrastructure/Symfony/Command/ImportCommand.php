<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\FileImporter;
use Eekes\SuluTranslationsBundle\Application\Io\ImportResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The command a deploy runs.
 *
 * New keys from the release appear with their file values; anything an editor changed since
 * the last deploy is left exactly as it is. That rule - and not the import itself - is what
 * makes it safe to put this in a deploy script.
 */
#[AsCommand(
    name: 'eekes:translations:import',
    description: 'Import translation files into the database without overwriting edited values.',
)]
class ImportCommand extends Command
{
    /**
     * @param list<string> $defaultPaths
     */
    public function __construct(
        private readonly FileImporter $importer,
        private readonly array $defaultPaths,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directory to read, repeatable. Defaults to the configured translation paths.')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these locales.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only this domain.')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Also replace values that already have content. This discards editor changes.')
            ->addOption('protect', null, InputOption::VALUE_NONE, 'Mark everything imported as protected, so editors cannot delete it.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $paths */
        $paths = $input->getOption('path');
        $paths = [] === $paths ? $this->defaultPaths : $paths;

        /** @var list<string> $locales */
        $locales = $input->getOption('locale');

        /** @var string|null $domain */
        $domain = $input->getOption('domain');

        $dryRun = (bool) $input->getOption('dry-run');
        $overwrite = (bool) $input->getOption('overwrite');

        $io->title($dryRun ? 'Translation import (dry run)' : 'Translation import');
        $io->listing($paths);

        $result = $dryRun
            ? $this->importer->preview($paths, [] === $locales ? null : $locales, $domain, $overwrite)
            : $this->importer->import($paths, [] === $locales ? null : $locales, $domain, $overwrite, (bool) $input->getOption('protect'));

        $this->report($io, $result);

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, ImportResult $result): void
    {
        $io->table(['', 'count'], [
            ['keys created', $result->keysCreated],
            ['values filled', $result->valuesCreated],
            ['values replaced', $result->valuesUpdated],
            ['unchanged', $result->valuesUnchanged],
            ['kept (edited in the admin)', $result->valuesSkipped],
        ]);

        if ([] !== $result->samples) {
            $io->section('Sample of the changes');
            $io->table(
                ['domain', 'key', 'locale', 'from', 'to'],
                \array_map(
                    static fn (array $sample): array => [
                        $sample['domain'],
                        self::shorten($sample['key']),
                        $sample['locale'],
                        self::shorten($sample['from'] ?? ''),
                        self::shorten($sample['to'] ?? ''),
                    ],
                    \array_slice($result->samples, 0, 20),
                ),
            );
        }

        if ([] !== $result->domainsSkipped) {
            $io->note(\sprintf(
                'Left alone: %s. Those catalogues are listed in eekes_sulu_translations.domains.ignore, so nothing imports them by itself. Pass --domain=%s to import one anyway.',
                \implode(', ', $result->domainsSkipped),
                $result->domainsSkipped[0],
            ));
        }

        if ($result->dryRun) {
            $io->note('Nothing was written. Run again without --dry-run to apply.');
        } else {
            $io->success('Import finished.');
        }
    }

    private static function shorten(string $value): string
    {
        return \mb_strlen($value) > 40 ? \mb_substr($value, 0, 39) . '…' : $value;
    }
}

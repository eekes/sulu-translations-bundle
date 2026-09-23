<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\CodeScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs Symfony's extractor over the project and records what it found, including where.
 *
 * Complements runtime discovery rather than replacing it: the extractor sees keys no page has
 * rendered yet, runtime discovery sees the keys that are built dynamically. Neither is enough
 * on its own, which is why `unused` needs both.
 */
#[AsCommand(
    name: 'eekes:translations:scan',
    description: 'Extract translation keys from templates and PHP, and record where they occur.',
)]
class ScanCommand extends Command
{
    public function __construct(private readonly CodeScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directory or file to scan, repeatable.')
            ->addOption('mark-orphans', null, InputOption::VALUE_NONE, 'Flag keys that no longer occur anywhere. Marks only; deleting is `prune`.')
            ->addOption('show-locations', null, InputOption::VALUE_NONE, 'Print the files each key was found in, not only how many were recorded.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $paths */
        $paths = $input->getOption('path');
        $showLocations = (bool) $input->getOption('show-locations');

        $summary = $this->scanner->scan(
            [] === $paths ? null : $paths,
            (bool) $input->getOption('mark-orphans'),
            (bool) $input->getOption('dry-run'),
            $showLocations,
        );

        if ($showLocations) {
            $this->renderLocations($io, $summary['locations']);
        }

        $io->table(['', 'count'], [
            ['keys found in code', $summary['seen']],
            ['new keys', $summary['created']],
            // Without these three the run after an import reads as a no-op: every key already
            // exists, so `new keys` is 0 while the locations behind every "Used in" panel were
            // just rewritten.
            ['locations recorded', $summary['recorded']],
            ['locations changed', $summary['changed']],
            ['no location found', $summary['unlocated']],
            ['skipped (ignored domains)', $summary['skipped']],
            ['newly orphaned', $summary['orphaned']],
            ['no longer orphaned', $summary['unorphaned']],
        ]);

        if ($summary['skipped'] > 0) {
            $io->note('Keys in a domain listed in eekes_sulu_translations.domains.ignore were counted but not created.');
        }

        if ($summary['unlocated'] > 0 && !$showLocations) {
            $io->note('Some keys were found without a file and line - a Twig extension, or a key built at runtime. Their "Used in" panel stays empty. Run with --show-locations to see which keys did get one.');
        }

        if ($summary['orphaned'] > 0) {
            $io->note('Orphaned keys are only flagged. Use `eekes:translations:prune` to delete them, and read what it says about dynamically built keys first.');
        }

        return Command::SUCCESS;
    }

    /**
     * One row per key, one line per file.
     *
     * A key used three times in one template is three occurrences in the database and one line
     * here - the same grouping the admin panel does, because a column of repeated paths answers
     * nothing the paths themselves do not.
     *
     * @param list<array{domain: string, key: string, occurrences: list<array{path: string, line: int}>}> $locations
     */
    private function renderLocations(SymfonyStyle $io, array $locations): void
    {
        if ([] === $locations) {
            $io->warning('Not one key could be traced back to a file. Check that eekes_sulu_translations.discovery.scan_paths points at this project\'s templates and PHP.');

            return;
        }

        \usort(
            $locations,
            static fn (array $left, array $right): int => [$left['domain'], $left['key']] <=> [$right['domain'], $right['key']],
        );

        $io->table(
            ['domain', 'key', 'used in'],
            \array_map(
                static function (array $location): array {
                    $byPath = [];

                    foreach ($location['occurrences'] as $occurrence) {
                        $byPath[$occurrence['path']][] = $occurrence['line'];
                    }

                    $files = [];

                    foreach ($byPath as $path => $lines) {
                        // Line 0 is the extractor saying "somewhere in this file" - worth
                        // printing the path for, not worth printing a ":0" for.
                        $lines = \array_values(\array_filter($lines, static fn (int $line): bool => $line > 0));

                        $files[] = [] === $lines ? $path : $path . ':' . \implode(', ', $lines);
                    }

                    return [$location['domain'], $location['key'], \implode("\n", $files)];
                },
                $locations,
            ),
        );
    }
}

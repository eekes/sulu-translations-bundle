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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $paths */
        $paths = $input->getOption('path');

        $summary = $this->scanner->scan(
            [] === $paths ? null : $paths,
            (bool) $input->getOption('mark-orphans'),
            (bool) $input->getOption('dry-run'),
        );

        $io->table(['', 'count'], [
            ['keys found in code', $summary['seen']],
            ['new keys', $summary['created']],
            ['skipped (ignored domains)', $summary['skipped']],
            ['newly orphaned', $summary['orphaned']],
            ['no longer orphaned', $summary['unorphaned']],
        ]);

        if ($summary['skipped'] > 0) {
            $io->note('Keys in a domain listed in eekes_sulu_translations.domains.ignore were counted but not created.');
        }

        if ($summary['orphaned'] > 0) {
            $io->note('Orphaned keys are only flagged. Use `eekes:translations:prune` to delete them, and read what it says about dynamically built keys first.');
        }

        return Command::SUCCESS;
    }
}

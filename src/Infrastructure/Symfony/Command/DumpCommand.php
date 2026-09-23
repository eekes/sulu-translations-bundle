<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\CatalogueExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes a snapshot to the dev dump directory so the IDE resolves keys.
 *
 * PhpStorm reads translation files, not databases, so without this every `|trans` in the
 * project is underlined and autocomplete is dead. It is a developer convenience and nothing at
 * runtime may depend on it.
 */
#[AsCommand(
    name: 'eekes:translations:dump',
    description: 'Write a snapshot of the database to the dump directory, for the IDE.',
)]
class DumpCommand extends Command
{
    public function __construct(
        private readonly CatalogueExporter $exporter,
        private readonly string $dumpPath,
        private readonly string $dumpFormat,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $summary = $this->exporter->export(null, null, $this->dumpFormat, $this->dumpPath, collectEvent: false);

        $io->success(\sprintf('Dumped %d messages to %s.', $summary['messages'], $summary['path']));

        return Command::SUCCESS;
    }
}

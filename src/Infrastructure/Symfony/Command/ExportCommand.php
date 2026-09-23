<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\CatalogueExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Writes the database back out to files - a deploy-time fallback for a site whose database is
 * unreachable, and the format an outside translator asks for.
 */
#[AsCommand(
    name: 'eekes:translations:export',
    description: 'Write the translations in the database to translation files.',
)]
class ExportCommand extends Command
{
    public function __construct(private readonly CatalogueExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these locales.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only this domain.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'yaml, xlf, csv, json, php - anything Symfony can write.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Where to write. Defaults to the configured export path.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $locales */
        $locales = $input->getOption('locale');
        /** @var string|null $format */
        $format = $input->getOption('format');

        if (null !== $format && !\in_array($format, $this->exporter->getSupportedFormats(), true)) {
            $io->error(\sprintf('Unknown format "%s". Available: %s.', $format, \implode(', ', $this->exporter->getSupportedFormats())));

            return Command::INVALID;
        }

        /** @var string|null $domain */
        $domain = $input->getOption('domain');
        /** @var string|null $path */
        $path = $input->getOption('path');

        $summary = $this->exporter->export([] === $locales ? null : $locales, $domain, $format, $path);

        $io->success(\sprintf(
            'Wrote %d messages for %s to %s as %s.',
            $summary['messages'],
            \implode(', ', $summary['locales']),
            $summary['path'],
            $summary['format'],
        ));

        return Command::SUCCESS;
    }
}

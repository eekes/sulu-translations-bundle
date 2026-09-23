<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslationRunner;
use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'eekes:translations:translate',
    description: 'Fill the empty values of one locale with a machine translation, for review.',
)]
class TranslateCommand extends Command
{
    public function __construct(
        private readonly MachineTranslationRunner $runner,
        private readonly string $sourceLocale,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source locale. Defaults to the configured source locale.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target locale.')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Only this domain.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many values.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many would be translated and call nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $target */
        $target = $input->getOption('to');

        if (null === $target) {
            $io->error('--to is required.');

            return Command::INVALID;
        }

        /** @var string $source */
        $source = $input->getOption('from') ?? $this->sourceLocale;

        if (!$this->runner->isAvailable($source, $target)) {
            $io->error(\sprintf('No machine translator is configured for "%s" to "%s". See eekes_sulu_translations.machine_translation.', $source, $target));

            return Command::FAILURE;
        }

        /** @var string|null $limit */
        $limit = $input->getOption('limit');

        /** @var string|null $domain */
        $domain = $input->getOption('domain');

        try {
            $summary = $this->runner->run(
                $source,
                $target,
                $domain,
                [],
                null === $limit ? null : (int) $limit,
                (bool) $input->getOption('dry-run'),
            );
        } catch (MachineTranslationException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(['', 'count'], [
            ['translated', $summary['translated']],
            ['skipped', $summary['skipped']],
            ['discarded (placeholders lost)', $summary['discarded']],
        ]);

        if (!(bool) $input->getOption('dry-run')) {
            $io->success(\sprintf('%d values written in %s. Machine output is not marked, so check it before you rely on it.', $summary['translated'], $target));
        }

        return Command::SUCCESS;
    }
}

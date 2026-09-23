<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * How far along each language is, and an exit code a release gate can act on.
 *
 * Translated or not - that is the only distinction the bundle makes about a value, and it is
 * the only one anyone needs from a progress report.
 */
#[AsCommand(
    name: 'eekes:translations:status',
    description: 'Show how complete each language is.',
)]
class StatusCommand extends Command
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly LocaleProvider $localeProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fail-on-missing', null, InputOption::VALUE_NONE, 'Exit non-zero when a language has untranslated keys. For a release gate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $total = $this->keyRepository->countAll();
        $rows = [];
        $missingAnywhere = 0;

        foreach ($this->localeProvider->getLocales() as $locale) {
            $translated = $this->keyRepository->countTranslated($locale);
            $missing = $total - $translated;
            $missingAnywhere += $missing;

            $rows[] = [
                $locale,
                $total,
                $translated,
                $missing,
                0 === $total ? '-' : \sprintf('%d%%', (int) \round(($translated / $total) * 100)),
            ];
        }

        $io->table(['language', 'keys', 'translated', 'missing', 'done'], $rows);

        if ((bool) $input->getOption('fail-on-missing') && $missingAnywhere > 0) {
            $io->error(\sprintf('%d values are still untranslated.', $missingAnywhere));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

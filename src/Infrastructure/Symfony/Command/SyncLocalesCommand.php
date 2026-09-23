<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Key\TranslationKeySynchronizer;
use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the value rows a newly configured locale needs.
 *
 * The admin list does this itself before every request, so this command is for a deploy that
 * adds a language and wants the work done once rather than on the first editor's page load.
 */
#[AsCommand(
    name: 'eekes:translations:sync-locales',
    description: 'Create the missing per-locale rows after a webspace gained a language.',
)]
class SyncLocalesCommand extends Command
{
    public function __construct(
        private readonly TranslationKeySynchronizer $synchronizer,
        private readonly LocaleProvider $localeProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $created = $this->synchronizer->synchroniseLocales();

        $io->success(\sprintf(
            'Created %d rows for the locales %s.',
            $created,
            \implode(', ', $this->localeProvider->getLocales()),
        ));

        return Command::SUCCESS;
    }
}

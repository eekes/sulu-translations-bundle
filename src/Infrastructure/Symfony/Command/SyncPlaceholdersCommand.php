<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Doctrine\ORM\EntityManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderExtractor;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderValidator;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the placeholders of every key out of its source-locale value and stores them.
 *
 * A key declares its placeholders the moment its source value is written, so an install that
 * has been running since before the column existed has none of them on file - and until it
 * has, the other languages are only checked against whatever the source row happens to say
 * at that moment. This is the one-off that fills them in; run it after the migration that
 * adds the column, and again whenever an import brought in a pile of new source strings.
 *
 * It reports, without changing anything, which languages do not carry what their key
 * declares. That is a **derived** count, not a status on the row: there is no status here and
 * there is not going to be one (see `write-path`). The same count is what the admin marks a
 * row with.
 */
#[AsCommand(
    name: 'eekes:translations:sync-placeholders',
    description: 'Store the placeholders every key declares, read from its source-locale value.',
)]
class SyncPlaceholdersCommand extends Command
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly PlaceholderExtractor $extractor,
        private readonly PlaceholderValidator $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $sourceLocale,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also derive again for keys that already declare their placeholders.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change and write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Translation placeholders');
        $io->text(\sprintf('Source language: <info>%s</info>', $this->sourceLocale));

        $declared = 0;
        $incomplete = [];

        foreach ($this->keyRepository->findForPlaceholderSync(!$input->getOption('all')) as $key) {
            $sourceValue = $key->getTranslation($this->sourceLocale)?->getValue();

            // Nothing to read them from. The key keeps declaring nothing, and the first
            // source-locale save fills it in.
            if (null === $sourceValue || '' === $sourceValue) {
                continue;
            }

            $tokens = $this->extractor->extractTokens($sourceValue);

            if ($key->getPlaceholders() !== $tokens) {
                if (!$dryRun) {
                    $key->setPlaceholders($tokens);
                    $this->keyRepository->save($key, false);
                }

                ++$declared;
            }

            if ([] === $tokens) {
                continue;
            }

            foreach ($key->getTranslations() as $translation) {
                if ($translation->getLocale() === $this->sourceLocale) {
                    continue;
                }

                $missing = $this->validator->findMissing($tokens, $translation->getValue());

                if ([] !== $missing) {
                    $incomplete[] = [
                        $key->getDomain(),
                        $key->getKey(),
                        $translation->getLocale(),
                        \implode(' ', $missing),
                    ];
                }
            }
        }

        if (!$dryRun) {
            // No catalogue bump: not a single translated string changed, only what the admin
            // knows about them.
            $this->entityManager->flush();
        }

        $io->success(\sprintf(
            '%s the placeholders of %d %s.',
            $dryRun ? 'Would store' : 'Stored',
            $declared,
            1 === $declared ? 'key' : 'keys',
        ));

        if ([] === $incomplete) {
            return Command::SUCCESS;
        }

        $io->warning(\sprintf('%d translations do not carry every placeholder their key needs.', \count($incomplete)));
        $io->table(['Domain', 'Key', 'Language', 'Missing'], \array_slice($incomplete, 0, 50));

        if (\count($incomplete) > 50) {
            $io->text(\sprintf('… and %d more.', \count($incomplete) - 50));
        }

        // Not a failure: those values are on the site and serving, and a deploy step that
        // exits non-zero over a sentence an editor has yet to fix is a deploy step somebody
        // removes.
        return Command::SUCCESS;
    }
}

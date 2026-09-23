<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Application\Io\MigrationGenerator;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes keys that have been flagged as orphaned for long enough.
 *
 * Separate from `scan` on purpose. A static extractor cannot see a key built from an enum, a
 * key concatenated at runtime or a key a Twig extension produces, so "the extractor did not
 * find it" is not evidence that nothing uses it. This command therefore needs an explicit age,
 * skips protected keys, and defaults to a dry run in everything but name - it prints what it
 * will delete before it does.
 *
 * `--migration` is the only way a deletion leaves this machine. Everything else here is a
 * local decision: the other environments keep the key, and their `status` and `unused`
 * reports keep counting it, until somebody runs this command there too - which is exactly the
 * per-environment command run that `generate-migration` exists to abolish. It is opt-in
 * because a generated `DELETE` is the one statement in this bundle that destroys data on a
 * target, and because plenty of projects are happy to leave the orphans where they are.
 */
#[AsCommand(
    name: 'eekes:translations:prune',
    description: 'Delete translation keys that have been orphaned for a while.',
)]
class PruneCommand extends Command
{
    public function __construct(
        private readonly TranslationKeyRepositoryInterface $keyRepository,
        private readonly TranslationManagerInterface $manager,
        private readonly MigrationGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('orphaned-since', null, InputOption::VALUE_REQUIRED, 'How long a key has to have been orphaned, as a date interval.', '90 days')
            ->addOption('include-protected', null, InputOption::VALUE_NONE, 'Also delete protected keys. Almost certainly not what you want.')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually delete. Without it the command only reports.')
            ->addOption('migration', null, InputOption::VALUE_NONE, 'Also write a Doctrine migration that deletes the same keys on every other environment. Needs --force.')
            ->addOption('migrations-dir', null, InputOption::VALUE_REQUIRED, 'Where to write the migration. Defaults to the configured migrations directory.')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Namespace of the generated migration class.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $since */
        $since = $input->getOption('orphaned-since');

        try {
            $before = new \DateTimeImmutable('-' . \ltrim($since, '-'));
        } catch (\Exception) {
            $io->error(\sprintf('"%s" is not a date interval. Try "90 days".', $since));

            return Command::INVALID;
        }

        $includeProtected = (bool) $input->getOption('include-protected');
        $migration = (bool) $input->getOption('migration');

        $keys = $this->keyRepository->findOrphanedBefore($before, $includeProtected);

        if ([] === $keys) {
            $io->success('Nothing has been orphaned that long.');

            return Command::SUCCESS;
        }

        $io->table(
            ['domain', 'key', 'orphaned since'],
            \array_map(
                static fn ($key): array => [$key->getDomain(), $key->getKey(), $key->getOrphanedAt()?->format('Y-m-d') ?? ''],
                \array_slice($keys, 0, 50),
            ),
        );

        if (!(bool) $input->getOption('force')) {
            $io->note(\sprintf(
                '%d keys would be deleted. Re-run with --force%s to delete them.',
                \count($keys),
                $migration ? ' --migration' : '',
            ));

            return Command::SUCCESS;
        }

        $deleted = [];

        foreach ($keys as $key) {
            try {
                $this->manager->deleteKey($key);
                $deleted[] = $key;
            } catch (TranslationWriteNotAllowedException $exception) {
                $io->warning($exception->getMessage());
            }
        }

        $io->success(\sprintf('Deleted %d keys. Anything still rendered will reappear with source "runtime".', \count($deleted)));

        if ($migration) {
            $this->writeMigration($io, $input, $deleted, $before, $includeProtected);
        }

        return Command::SUCCESS;
    }

    /**
     * Writes the migration *after* the deletion, from the keys that actually went.
     *
     * The other way round would put a key in the migration that a read-only domain then
     * refused to delete here, and the release would remove on production what is still in the
     * admin locally. The cost of this order is that a failing write leaves nothing to
     * generate from a second time - the rows are gone - which is the known trade-off of
     * keeping the deletions out of the schema. A deleted entity still carries its domain and
     * hash, so reading them here is safe.
     *
     * @param list<TranslationKey> $deleted
     */
    private function writeMigration(
        SymfonyStyle $io,
        InputInterface $input,
        array $deleted,
        \DateTimeImmutable $before,
        bool $includeProtected,
    ): void {
        if ([] === $deleted) {
            $io->note('Nothing was deleted, so no migration was written.');

            return;
        }

        /** @var string|null $directory */
        $directory = $input->getOption('migrations-dir');
        /** @var string|null $namespace */
        $namespace = $input->getOption('namespace');

        $result = $this->generator->generateDeletions($deleted, $before, $includeProtected);
        $written = $this->generator->write($result, $directory, $namespace);

        $io->success(\sprintf('Wrote %s: deletes %d keys.', $written['path'], $result['keys']));

        $io->text([
            'Commit it. Every other environment drops the same keys on its next migration run:',
            '',
            '    <info>bin/console doctrine:migrations:migrate</info>',
            '',
            'A key an environment has rendered since it was orphaned here is kept there, and so',
            'is anything protected. Running it here again is a no-op.',
        ]);
    }
}

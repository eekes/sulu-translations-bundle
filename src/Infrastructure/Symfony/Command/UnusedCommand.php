<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\Command;

use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The report that combines both signals: not found by the extractor **and** not rendered
 * within the window. Either one alone lies; together they are worth acting on.
 */
#[AsCommand(
    name: 'eekes:translations:unused',
    description: 'List keys that neither the extractor nor the site has seen lately.',
)]
class UnusedCommand extends Command
{
    public function __construct(private readonly TranslationKeyRepositoryInterface $keyRepository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'How long a key has to have gone unrendered.', '90 days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $since */
        $since = $input->getOption('since');

        try {
            $usedSince = new \DateTimeImmutable('-' . \ltrim($since, '-'));
        } catch (\Exception) {
            $io->error(\sprintf('"%s" is not a date interval. Try "90 days".', $since));

            return Command::INVALID;
        }

        // No hashes: this report deliberately does not run the extractor, so it answers
        // "nothing has rendered this" and leaves "the extractor cannot see it" to `scan`.
        $keys = $this->keyRepository->findUnused([], $usedSince);

        if ([] === $keys) {
            $io->success('Every key has been rendered within the window.');

            return Command::SUCCESS;
        }

        $io->table(
            ['domain', 'key', 'last used', 'orphaned'],
            \array_map(
                static fn (TranslationKey $key): array => [
                    $key->getDomain(),
                    $key->getKey(),
                    $key->getLastUsedAt()?->format('Y-m-d') ?? 'never',
                    $key->isOrphaned() ? 'yes' : 'no',
                ],
                \array_slice($keys, 0, 100),
            ),
        );

        $io->note(\sprintf(
            '%d keys. Run `eekes:translations:scan --mark-orphans` first: a key the extractor still finds is in a template nobody visited, not an unused key.',
            \count($keys),
        ));

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Machine;

use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Validation\IcuMessageValidator;
use Eekes\SuluTranslationsBundle\Domain\Event\TranslationsMachineTranslatedEvent;
use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;
use Eekes\SuluTranslationsBundle\Domain\Exception\MachineTranslationException;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationExceptionInterface;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Fills the empty values of one locale from another, in batches, and leaves every one of them
 * in the review queue.
 *
 * Never touches a value that already has content, so a second run is free and an editor's work
 * is never overwritten by a machine.
 */
class MachineTranslationRunner
{
    public function __construct(
        private readonly MachineTranslatorInterface $translator,
        private readonly TranslationRepositoryInterface $translationRepository,
        private readonly TranslationManagerInterface $manager,
        private readonly PlaceholderProtector $protector,
        private readonly IcuMessageSplitter $icuSplitter,
        private readonly IcuMessageValidator $icuValidator,
        private readonly int $batchSize = 50,
        private readonly int $maxPerRun = 500,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isAvailable(string $sourceLocale, string $targetLocale): bool
    {
        return $this->translator->supports($sourceLocale, $targetLocale);
    }

    /**
     * One string, translated and handed back **without being stored**.
     *
     * What the translate button in the form uses. Nothing is written until the editor saves,
     * so this is the one path that is allowed to produce a suggestion for a field that
     * already has content - a run over the whole catalogue never is.
     *
     * @throws MachineTranslationException
     *
     * @return string|null null when the result lost a placeholder and had to be thrown away
     */
    public function translateText(string $text, string $sourceLocale, string $targetLocale): ?string
    {
        if ('' === \trim($text)) {
            return null;
        }

        if (!$this->translator->supports($sourceLocale, $targetLocale)) {
            throw MachineTranslationException::unsupportedPair($sourceLocale, $targetLocale);
        }

        $segments = $this->icuSplitter->split($text);

        if (null !== $segments) {
            return $this->translateIcu($segments, $sourceLocale, $targetLocale);
        }

        [$protectedText, $map] = $this->protector->protect($text);
        $results = $this->translator->translate([$protectedText], $sourceLocale, $targetLocale);

        if (1 !== \count($results)) {
            throw MachineTranslationException::countMismatch(1, \count($results));
        }

        $value = $this->protector->restore($results[0], $map);

        return null === $value || '' === \trim($value) ? null : $value;
    }

    /**
     * A plural or select message: the branch bodies go to the engine as separate texts and
     * the skeleton is carried through untouched - see `IcuMessageSplitter` for why sending the
     * whole thing is how a perfectly good key ends up refused as unparseable.
     *
     * What comes back is parsed before it is handed over. The structure cannot have moved, but
     * a translation is free to contain an apostrophe or a brace of its own, and ICU reads
     * both; a message that no longer parses is discarded exactly like one that lost a
     * placeholder.
     *
     * @param array{template: string, literals: list<string>} $segments
     *
     * @throws MachineTranslationException
     */
    private function translateIcu(array $segments, string $sourceLocale, string $targetLocale): ?string
    {
        $texts = [];
        $maps = [];

        foreach ($segments['literals'] as $index => $literal) {
            [$texts[$index], $maps[$index]] = $this->protector->protect($literal);
        }

        $results = $this->translator->translate(\array_values($texts), $sourceLocale, $targetLocale);

        if (\count($results) !== \count($texts)) {
            throw MachineTranslationException::countMismatch(\count($texts), \count($results));
        }

        $literals = [];

        foreach (\array_values($results) as $index => $result) {
            $restored = $this->protector->restore($result, $maps[$index]);

            if (null === $restored || '' === \trim($restored)) {
                return null;
            }

            $literals[] = $restored;
        }

        $value = $this->icuSplitter->join($segments['template'], $literals);

        try {
            $this->icuValidator->validate($value, $targetLocale);
        } catch (InvalidIcuMessageException $exception) {
            $this->logger?->warning('A machine translated ICU message was discarded.', [
                'exception' => $exception,
                'locale' => $targetLocale,
            ]);

            return null;
        }

        return $value;
    }

    /**
     * @param list<int> $onlyKeyIds restricts the run to the rows the editor is looking at
     */
    public function countPending(string $sourceLocale, string $targetLocale, ?string $domain = null, array $onlyKeyIds = []): int
    {
        return \count($this->translationRepository->findUntranslated($sourceLocale, $targetLocale, $domain, $onlyKeyIds, $this->maxPerRun));
    }

    /**
     * @param list<int> $onlyKeyIds
     *
     * @return array{translated: int, skipped: int, discarded: int}
     */
    public function run(
        string $sourceLocale,
        string $targetLocale,
        ?string $domain = null,
        array $onlyKeyIds = [],
        ?int $limit = null,
        bool $dryRun = false,
    ): array {
        if (!$this->translator->supports($sourceLocale, $targetLocale)) {
            throw MachineTranslationException::unsupportedPair($sourceLocale, $targetLocale);
        }

        $limit = \min($limit ?? $this->maxPerRun, $this->maxPerRun);
        $pending = $this->translationRepository->findUntranslated($sourceLocale, $targetLocale, $domain, $onlyKeyIds, $limit);

        if ($dryRun) {
            return ['translated' => \count($pending), 'skipped' => 0, 'discarded' => 0];
        }

        $translated = 0;
        $skipped = 0;
        $discarded = 0;

        // A plural or select message cannot ride along in a batch: its branches are separate
        // texts, and how many of them there are differs per row. There are never many, and
        // they are the rows most likely to need a person afterwards anyway.
        $icu = [];
        $plain = [];

        foreach ($pending as $row) {
            if (null === $this->icuSplitter->split($row['source'])) {
                $plain[] = $row;
            } else {
                $icu[] = $row;
            }
        }

        foreach ($icu as $row) {
            try {
                $value = $this->translateText($row['source'], $sourceLocale, $targetLocale);
            } catch (MachineTranslationException $exception) {
                $this->logger?->error('A machine translation failed.', ['exception' => $exception]);
                ++$skipped;

                continue;
            }

            if (null === $value) {
                ++$discarded;

                continue;
            }

            $this->store($row, $value, $translated, $skipped, $discarded);
        }

        foreach (\array_chunk($plain, \max(1, $this->batchSize)) as $chunk) {
            $texts = [];
            $maps = [];

            foreach ($chunk as $index => $row) {
                [$protectedText, $map] = $this->protector->protect($row['source']);
                $texts[$index] = $protectedText;
                $maps[$index] = $map;
            }

            try {
                $results = $this->translator->translate(\array_values($texts), $sourceLocale, $targetLocale);
            } catch (MachineTranslationException $exception) {
                $this->logger?->error('A machine translation batch failed.', ['exception' => $exception]);
                $skipped += \count($chunk);

                continue;
            }

            if (\count($results) !== \count($chunk)) {
                throw MachineTranslationException::countMismatch(\count($chunk), \count($results));
            }

            foreach (\array_values($chunk) as $index => $row) {
                $value = $this->protector->restore($results[$index], $maps[$index]);

                if (null === $value || '' === \trim($value)) {
                    // A result that lost a placeholder is worse than no result: the write path
                    // would refuse it anyway, and a half-translated string on a live page is
                    // harder to spot than an untranslated one.
                    ++$discarded;

                    continue;
                }

                $this->store($row, $value, $translated, $skipped, $discarded);
            }
        }

        $summary = ['translated' => $translated, 'skipped' => $skipped, 'discarded' => $discarded];


        $this->manager->commit(new TranslationsMachineTranslatedEvent(
            $summary + ['source' => $sourceLocale, 'target' => $targetLocale],
            $targetLocale,
        ));

        return $summary;
    }

    /**
     * Hands one finished value to the write path, and counts what happened.
     *
     * **Every refusal is caught.** The manager validates what it is given - placeholders, ICU,
     * a read-only domain - and any one of those throwing takes down a run of five hundred rows
     * over one of them. A refused row is a discarded row, which is what `discarded` has always
     * meant: a result that was not good enough to store.
     *
     * @param array{id: int, source: string} $row
     */
    private function store(array $row, string $value, int &$translated, int &$skipped, int &$discarded): void
    {
        $translation = $this->translationRepository->findById($row['id']);

        if (null === $translation) {
            ++$skipped;

            return;
        }

        try {
            $staged = $this->manager->stageValue($translation, $value);
        } catch (TranslationExceptionInterface $exception) {
            $this->logger?->warning('A machine translation was refused by the write path.', [
                'exception' => $exception,
                'id' => $row['id'],
            ]);
            ++$discarded;

            return;
        }

        if (null !== $staged) {
            ++$translated;
        } else {
            ++$skipped;
        }
    }
}

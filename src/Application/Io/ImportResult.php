<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Application\Io;

/**
 * What an import did, or would do.
 *
 * Carries samples as well as counts because the admin shows a diff preview before it writes
 * anything: an import that silently overwrites eight hundred edited strings is how a bundle
 * loses a user's trust exactly once.
 */
final class ImportResult
{
    /**
     * @param list<array{domain: string, key: string, locale: string, from: string|null, to: string|null, type: string}> $samples
     * @param list<string> $domainsSkipped catalogues left alone because of domains.ignore
     */
    public function __construct(
        public readonly int $keysCreated = 0,
        public readonly int $valuesCreated = 0,
        public readonly int $valuesUpdated = 0,
        public readonly int $valuesUnchanged = 0,
        public readonly int $valuesSkipped = 0,
        public readonly array $samples = [],
        public readonly bool $dryRun = false,
        public readonly array $domainsSkipped = [],
    ) {
    }

    /**
     * @param list<array{domain: string, key: string, locale: string, from: string|null, to: string|null, type: string}> $samples
     * @param list<string> $domainsSkipped
     */
    public function with(
        int $keysCreated = 0,
        int $valuesCreated = 0,
        int $valuesUpdated = 0,
        int $valuesUnchanged = 0,
        int $valuesSkipped = 0,
        array $samples = [],
        array $domainsSkipped = [],
    ): self {
        return new self(
            $this->keysCreated + $keysCreated,
            $this->valuesCreated + $valuesCreated,
            $this->valuesUpdated + $valuesUpdated,
            $this->valuesUnchanged + $valuesUnchanged,
            $this->valuesSkipped + $valuesSkipped,
            \array_merge($this->samples, $samples),
            $this->dryRun,
            // One locale per file means the same domain is skipped once per language. The
            // report names it, it does not count it.
            \array_values(\array_unique(\array_merge($this->domainsSkipped, $domainsSkipped))),
        );
    }

    public function asDryRun(bool $dryRun = true): self
    {
        return new self(
            $this->keysCreated,
            $this->valuesCreated,
            $this->valuesUpdated,
            $this->valuesUnchanged,
            $this->valuesSkipped,
            $this->samples,
            $dryRun,
            $this->domainsSkipped,
        );
    }

    public function getTouched(): int
    {
        return $this->valuesCreated + $this->valuesUpdated;
    }

    /**
     * @return array<string, int|bool>
     */
    public function toArray(): array
    {
        return [
            'keysCreated' => $this->keysCreated,
            'valuesCreated' => $this->valuesCreated,
            'valuesUpdated' => $this->valuesUpdated,
            'valuesUnchanged' => $this->valuesUnchanged,
            'valuesSkipped' => $this->valuesSkipped,
            'dryRun' => $this->dryRun,
            'domainsSkipped' => \count($this->domainsSkipped),
        ];
    }
}

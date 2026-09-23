<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Machine;

use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslatorInterface;

/**
 * A hand-written engine, because what these tests assert on is what the runner *sends*.
 *
 * A mock with a `willReturnCallback` cannot do that job honestly: the callback does not
 * inherit the signature of the method it stands in for, so every batch arrives as a bare
 * `array` and the recorded batches lose their type. Implementing the interface keeps
 * `list<string>` in and `list<string>` out, which is exactly the contract under test.
 */
final class RecordingMachineTranslator implements MachineTranslatorInterface
{
    /**
     * @var list<list<string>> every batch of texts the engine was handed
     */
    public array $sent = [];

    /**
     * @param list<string> $results what the engine answers, in order
     */
    public function __construct(private readonly array $results)
    {
    }

    public function translate(array $texts, string $sourceLocale, string $targetLocale): array
    {
        $this->sent[] = $texts;

        return \array_slice($this->results, 0, \count($texts));
    }

    public function supports(string $sourceLocale, string $targetLocale): bool
    {
        return true;
    }
}

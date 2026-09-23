<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Machine;

use Eekes\SuluTranslationsBundle\Application\Machine\IcuMessageSplitter;
use Eekes\SuluTranslationsBundle\Application\Machine\MachineTranslationRunner;
use Eekes\SuluTranslationsBundle\Application\Machine\PlaceholderProtector;
use Eekes\SuluTranslationsBundle\Application\Manager\TranslationManagerInterface;
use Eekes\SuluTranslationsBundle\Application\Validation\IcuMessageValidator;
use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;
use PHPUnit\Framework\TestCase;

class MachineTranslationRunnerTest extends TestCase
{
    private ?RecordingMachineTranslator $translator = null;

    public function testAPluralMessageIsSentAsSentencesAndComesBackAsAMessage(): void
    {
        // The bug this covers: the whole `{count, plural, one {…} other {…}}` used to go to
        // the engine in one piece, came back with the keywords translated or the braces
        // moved, and the write path refused it - stopping a run on a key nobody had touched.
        $runner = $this->runner(['# artikel in uw mandje', '# artikelen in uw mandje']);

        $value = $runner->translateText(
            '{count, plural, one {# item in your cart} other {# items in your cart}}',
            'en',
            'nl',
        );

        self::assertSame(
            '{count, plural, one {# artikel in uw mandje} other {# artikelen in uw mandje}}',
            $value,
        );

        // Two sentences, no syntax: `one {` is not something to translate.
        self::assertSame([['# item in your cart', '# items in your cart']], $this->sent());
    }

    public function testAResultThatNoLongerParsesIsDiscardedRatherThanStored(): void
    {
        // The structure cannot move, but a translation may bring a brace or a quote of its
        // own. Better no translation than one that throws on the page that renders it.
        $runner = $this->runner(['# artikel {', 'x']);

        self::assertNull($runner->translateText('{count, plural, one {# item} other {x}}', 'en', 'nl'));
    }

    public function testAPlainSentenceStillGoesInOnePieceWithItsPlaceholdersProtected(): void
    {
        $runner = $this->runner(['Hallo <x id="0"/>, lees meer']);

        self::assertSame('Hallo %name%, lees meer', $runner->translateText('Hello %name%, read more', 'en', 'nl'));
        self::assertSame([['Hello <x id="0"/>, read more']], $this->sent());
    }

    public function testARowTheWritePathRefusesDoesNotTakeTheRunDownWithIt(): void
    {
        // A run of five hundred rows used to die on the first one the manager refused - an
        // ICU message it could not parse, a placeholder the engine dropped - and everything
        // after it went untranslated with nothing said about why.
        $manager = $this->createMock(TranslationManagerInterface::class);
        $manager->method('stageValue')->willReturnCallback(
            static function (Translation $translation, ?string $value) {
                if ('refused' === $value) {
                    throw new InvalidIcuMessageException('U_ILLEGAL_ARGUMENT_ERROR', 'fr');
                }

                return null;
            },
        );

        $repository = $this->createMock(TranslationRepositoryInterface::class);
        $repository->method('findUntranslated')->willReturn([
            ['id' => 1, 'keyId' => 1, 'key' => 'a', 'domain' => 'messages', 'icu' => false, 'source' => 'first'],
            ['id' => 2, 'keyId' => 2, 'key' => 'b', 'domain' => 'messages', 'icu' => false, 'source' => 'second'],
        ]);
        $repository->method('findById')->willReturn(new Translation(new TranslationKey('messages', 'a'), 'fr'));

        $runner = new MachineTranslationRunner(
            new RecordingMachineTranslator(['refused', 'fine']),
            $repository,
            $manager,
            new PlaceholderProtector(),
            new IcuMessageSplitter(),
            new IcuMessageValidator(),
        );

        $summary = $runner->run('en', 'fr');

        // Refused rows are discarded, like any other result that was not good enough to keep,
        // and the rest of the run happened.
        self::assertSame(1, $summary['discarded']);
        self::assertSame(1, $summary['skipped']);
    }

    /**
     * @param list<string> $results what the engine answers, in order
     */
    private function runner(array $results): MachineTranslationRunner
    {
        $this->translator = new RecordingMachineTranslator($results);

        return new MachineTranslationRunner(
            $this->translator,
            $this->createMock(TranslationRepositoryInterface::class),
            $this->createMock(TranslationManagerInterface::class),
            new PlaceholderProtector(),
            new IcuMessageSplitter(),
            new IcuMessageValidator(),
        );
    }

    /**
     * @return list<list<string>>
     */
    private function sent(): array
    {
        self::assertNotNull($this->translator, 'No runner was built, so nothing was sent.');

        return $this->translator->sent;
    }
}

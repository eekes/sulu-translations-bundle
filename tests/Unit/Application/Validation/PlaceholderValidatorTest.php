<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Application\Validation;

use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderExtractor;
use Eekes\SuluTranslationsBundle\Application\Validation\PlaceholderValidator;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderMismatchException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderStillInUseException;
use PHPUnit\Framework\TestCase;

class PlaceholderValidatorTest extends TestCase
{
    public function testAcceptsMatchingPlaceholders(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(['%name%'], 'Bonjour %name%', 'fr');
    }

    public function testAcceptsReorderedPlaceholders(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->validate(['%a%', '%b%'], '%b% dan %a%', 'nl');
    }

    public function testAcceptsAnotherNotationForTheSameArgument(): void
    {
        $this->expectNotToPerformAssertions();

        // The comparison is of argument names: an editor who wrote the ICU form of a message
        // the source writes Symfony-style has not broken anything.
        $this->validator()->validate(['%count%'], 'Er zijn {count} berichten', 'nl');
    }

    public function testRefusesAMissingPlaceholder(): void
    {
        $this->expectException(PlaceholderMismatchException::class);

        $this->validator()->validate(['%name%'], 'Bonjour', 'fr');
    }

    public function testTheExceptionNamesWhatIsWrong(): void
    {
        try {
            $this->validator()->validate(['%name%'], 'Bonjour %nom%', 'fr');

            self::fail('The mismatch should have been refused.');
        } catch (PlaceholderMismatchException $exception) {
            // The admin renders these two lists as a sentence in the editor's own language, so
            // they are part of the contract, not just of the message.
            self::assertSame(['name'], $exception->getMissing());
            self::assertSame(['nom'], $exception->getUnexpected());
            self::assertSame('fr', $exception->getLocale());
        }
    }

    public function testOffModeAcceptsAnything(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator(PlaceholderValidator::MODE_OFF)->validate(['%name%'], 'Bonjour', 'fr');
    }

    public function testAnEmptyTranslationIsNotAMismatch(): void
    {
        $this->expectNotToPerformAssertions();

        // Clearing a translation is how an editor says "not translated", and it must not be
        // refused for lacking placeholders.
        $this->validator()->validate(['%name%'], null, 'fr');
        $this->validator()->validate(['%name%'], '', 'fr');
    }

    public function testAKeyThatDeclaresNothingIsNotAConstraint(): void
    {
        $this->expectNotToPerformAssertions();

        // `null` is "nobody has read a source value yet", which is not a reason to refuse a
        // translation somebody is writing now.
        $this->validator()->validate(null, 'Bonjour %nom%', 'fr');
        $this->validator()->validate([], 'Bonjour %nom%', 'fr');
    }

    public function testFindMissingReportsTheTokensAndNotTheNames(): void
    {
        // What the admin puts in front of an editor is what they have to type, so the token
        // travels all the way through rather than the bare argument name.
        self::assertSame(['%name%'], $this->validator()->findMissing(['%name%', '{count}'], 'Bonjour {count}'));
        self::assertSame([], $this->validator()->findMissing(['%name%'], 'Bonjour %name%'));

        // An untranslated row is not a broken one.
        self::assertSame([], $this->validator()->findMissing(['%name%'], null));
    }

    public function testFindMissingIgnoresTheMode(): void
    {
        // Marking a row that lost a placeholder is worth doing whether or not writes are
        // being refused - `off` says "do not block me", not "do not tell me".
        self::assertSame(
            ['%name%'],
            $this->validator(PlaceholderValidator::MODE_OFF)->findMissing(['%name%'], 'Bonjour'),
        );
    }

    public function testAddingAPlaceholderToTheSourceIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        // Refusing this would deadlock the pair: no translation may carry an argument the
        // source has not got, so no source could ever gain one.
        $this->validator()->assertRedefinable(['%name%'], ['%name%', '%count%'], ['fr' => 'Bonjour %name%']);
    }

    public function testRemovingAPlaceholderNobodyUsesIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator()->assertRedefinable(['%name%'], [], ['fr' => 'Bonjour', 'nl' => 'Hallo']);
    }

    public function testRemovingAPlaceholderAnotherLanguageStillUsesIsRefused(): void
    {
        try {
            $this->validator()->assertRedefinable(
                ['%name%', '%count%'],
                ['%count%'],
                ['fr' => 'Bonjour %name%', 'de' => 'Hallo %name%', 'nl' => 'Hallo'],
            );

            self::fail('Removing a placeholder that is still in use should have been refused.');
        } catch (PlaceholderStillInUseException $exception) {
            self::assertSame(['%name%'], $exception->getPlaceholders());
            // Only the languages that actually carry it: an editor is being sent to those rows.
            self::assertSame(['fr', 'de'], $exception->getLocales());
        }
    }

    public function testRemovalIsJudgedOnTheArgumentAndNotTheNotation(): void
    {
        $this->expectException(PlaceholderStillInUseException::class);

        // The Dutch wrote it ICU-style. It is the same argument, and it breaks the same way.
        $this->validator()->assertRedefinable(['%name%'], [], ['nl' => 'Hallo {name}']);
    }

    public function testOffModeNeverRefusesARedefinition(): void
    {
        $this->expectNotToPerformAssertions();

        $this->validator(PlaceholderValidator::MODE_OFF)
            ->assertRedefinable(['%name%'], [], ['fr' => 'Bonjour %name%']);
    }

    private function validator(string $mode = PlaceholderValidator::MODE_STRICT): PlaceholderValidator
    {
        return new PlaceholderValidator(new PlaceholderExtractor(), $mode);
    }
}

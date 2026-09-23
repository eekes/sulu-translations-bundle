<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Domain\Exception\InvalidIcuMessageException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderMismatchException;
use Eekes\SuluTranslationsBundle\Domain\Exception\PlaceholderStillInUseException;
use Eekes\SuluTranslationsBundle\Domain\Exception\TranslationWriteNotAllowedException;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;

/**
 * The six things that have to happen together on every write, and in order.
 */
class TranslationManagerTest extends TranslationsTestCase
{
    public function testAPlaceholderMismatchIsRefused(): void
    {
        self::givenTranslation('checkout.greeting', 'en', 'Hello %name%');
        $french = self::requireKey('checkout.greeting')->getTranslation('fr');
        self::assertNotNull($french);

        $this->expectException(PlaceholderMismatchException::class);

        self::getTranslationManager()->saveValue($french, 'Bonjour');
    }

    public function testMatchingPlaceholdersAreAccepted(): void
    {
        self::givenTranslation('checkout.greeting', 'en', 'Hello %name%');
        $french = self::requireKey('checkout.greeting')->getTranslation('fr');
        self::assertNotNull($french);

        self::getTranslationManager()->saveValue($french, 'Bonjour %name%');

        self::assertSame('Bonjour %name%', $french->getValue());
    }

    public function testTheSourceLocaleItselfIsNotValidatedAgainstItself(): void
    {
        // Nothing to compare against, and refusing the source would make it impossible to
        // introduce a placeholder at all.
        $english = self::givenTranslation('free.form', 'en', 'Anything %goes%');

        self::assertSame('Anything %goes%', $english->getValue());
    }

    public function testTheSourceValueDeclaresThePlaceholdersOfTheKey(): void
    {
        self::givenTranslation('checkout.total', 'en', 'Hello %name%, you have {count} messages');
        self::getEntityManager()->clear();

        // Stored on the key as writable tokens, in the order they appear: this is what the
        // admin puts in front of an editor, so `count` would be of no use to anybody.
        self::assertSame(['%name%', '{count}'], self::requireKey('checkout.total')->getPlaceholders());
    }

    public function testAKeyWithNoSourceValueDeclaresNothing(): void
    {
        self::givenTranslation('nothing.declared', 'fr', 'Bonjour %nom%');

        // `null`, not `[]`: nobody has read a source value yet, and that is not the same as
        // "this key takes no arguments". Nothing is enforced until one arrives.
        self::assertNull(self::requireKey('nothing.declared')->getPlaceholders());
    }

    public function testClearingTheSourceValueKeepsWhatTheKeyDeclares(): void
    {
        $english = self::givenTranslation('kept.declaration', 'en', 'Hello %name%');
        self::getTranslationManager()->saveValue($english, null);

        // The hole this closes: deriving the set from the sibling row on every write meant an
        // emptied source switched the check off for every other language at once.
        self::assertSame(['%name%'], self::requireKey('kept.declaration')->getPlaceholders());

        $french = self::requireKey('kept.declaration')->getTranslation('fr');
        self::assertNotNull($french);

        $this->expectException(PlaceholderMismatchException::class);

        self::getTranslationManager()->saveValue($french, 'Bonjour');
    }

    public function testTheSourceMayGainAPlaceholder(): void
    {
        $english = self::givenTranslation('gaining.one', 'en', 'Hello');
        $french = self::requireKey('gaining.one')->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Bonjour');

        // Refusing this would deadlock the pair: French may not carry an argument English has
        // not got, so English could never be the first to gain one.
        self::getTranslationManager()->saveValue($english, 'Hello %name%');

        self::assertSame(['%name%'], self::requireKey('gaining.one')->getPlaceholders());
    }

    public function testTheSourceMayDropAPlaceholderNobodyUses(): void
    {
        $english = self::givenTranslation('dropping.unused', 'en', 'Hello %name%');

        self::getTranslationManager()->saveValue($english, 'Hello');

        self::assertSame([], self::requireKey('dropping.unused')->getPlaceholders());
    }

    public function testTheSourceMayNotDropAPlaceholderAnotherLanguageStillUses(): void
    {
        $english = self::givenTranslation('dropping.used', 'en', 'Hello %name%');
        $french = self::requireKey('dropping.used')->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Bonjour %name%');

        try {
            self::getTranslationManager()->saveValue($english, 'Hello');

            self::fail('Dropping a placeholder that French still carries should have been refused.');
        } catch (PlaceholderStillInUseException $exception) {
            self::assertSame(['%name%'], $exception->getPlaceholders());
            self::assertSame(['fr'], $exception->getLocales());
        }

        self::getEntityManager()->clear();

        // Nothing was written: not the English value, and not the declaration.
        self::assertSame('Hello %name%', self::valueOf('dropping.used', 'en'));
        self::assertSame(['%name%'], self::requireKey('dropping.used')->getPlaceholders());
    }

    public function testATranslationIsCheckedAgainstTheKeyAndNotAgainstTheSourceRow(): void
    {
        self::givenTranslation('declared.only', 'en', 'Hello %name%');

        $key = self::requireKey('declared.only');
        $german = $key->getTranslation('de');
        self::assertNotNull($german);

        // Writing the same argument in the ICU notation is the same argument.
        self::getTranslationManager()->saveValue($german, 'Hallo {name}');

        self::assertSame('Hallo {name}', $german->getValue());
    }

    public function testTheTranslatedFlagFollowsTheValue(): void
    {
        $translation = self::givenTranslation('flag.check', 'en', null);
        self::assertFalse($translation->isTranslated());

        self::getTranslationManager()->saveValue($translation, 'Something');
        self::assertTrue($translation->isTranslated());

        // Clearing a value is how an editor says "not translated", and the flag the list
        // filters on has to agree with them immediately.
        self::getTranslationManager()->saveValue($translation, null);
        self::assertFalse($translation->isTranslated());

        // An empty string is the same thing arriving from a form field.
        self::getTranslationManager()->saveValue($translation, 'Something');
        self::getTranslationManager()->saveValue($translation, '');
        self::assertFalse($translation->isTranslated());
        self::assertNull($translation->getValue());
    }

    public function testTheTranslatedFlagIsWhatTheDatabaseFiltersOn(): void
    {
        self::givenTranslation('flag.persisted', 'en', 'Something');
        self::getEntityManager()->clear();

        // Read back from the database rather than from the object we just wrote: the flag is
        // a stored column, and a filter that disagrees with the value is worse than no filter.
        self::assertTrue(self::requireKey('flag.persisted')->getTranslation('en')?->isTranslated());
        self::assertFalse(self::requireKey('flag.persisted')->getTranslation('fr')?->isTranslated());
    }

    public function testEveryChangeAppendsThePreviousValue(): void
    {
        $translation = self::givenTranslation('history.check', 'en', 'One');
        self::getTranslationManager()->saveValue($translation, 'Two');
        self::getTranslationManager()->saveValue($translation, 'Three');

        $values = \array_map(
            static fn (TranslationVersion $version): ?string => $version->getValue(),
            self::getVersionRepository()->findByTranslation($translation),
        );

        // Newest first, and the newest version is what it said *before* the last edit.
        self::assertSame(['Two', 'One', null], \array_values($values));
    }

    public function testRestoringPutsTheValueBackAndIsItselfAVersion(): void
    {
        $translation = self::givenTranslation('restore.check', 'en', 'One');
        self::getTranslationManager()->saveValue($translation, 'Two');

        $repository = self::getVersionRepository();
        $versions = $repository->findByTranslation($translation);
        $toRestore = $versions[0];

        self::assertSame('One', $toRestore->getValue());

        self::getTranslationManager()->restoreVersion($toRestore);

        self::assertSame('One', $translation->getValue());
        // Undoing an undo has to work.
        self::assertSame('Two', $repository->findByTranslation($translation)[0]->getValue());
    }

    public function testNoVersionIsWrittenWhenNothingChanged(): void
    {
        $translation = self::givenTranslation('noop.check', 'en', 'Same');
        $repository = self::getVersionRepository();
        $before = $repository->countByTranslation($translation);

        // The inline editor and the form both send a PUT on blur.
        self::getTranslationManager()->saveValue($translation, 'Same');

        self::assertSame($before, $repository->countByTranslation($translation));
    }

    public function testIcuCanBeSwitchedOnAfterTheFact(): void
    {
        if (!\class_exists(\MessageFormatter::class)) {
            self::markTestSkipped('The intl extension is required.');
        }

        self::givenTranslation('icu.later', 'en', '{count, plural, one {# item} other {# items}}');
        $key = self::requireKey('icu.later');

        self::assertFalse($key->isIcu());
        self::assertTrue(self::getTranslationManager()->setIcu($key, true));
        self::assertTrue($key->isIcu());

        // Same call again changes nothing and says so.
        self::assertFalse(self::getTranslationManager()->setIcu($key, true));
    }

    public function testSwitchingIcuOnValidatesEveryLanguageNotJustTheCurrentOne(): void
    {
        if (!\class_exists(\MessageFormatter::class)) {
            self::markTestSkipped('The intl extension is required.');
        }

        self::givenTranslation('icu.broken', 'en', '{count, plural, one {# item} other {# items}}');
        $key = self::requireKey('icu.broken');

        $dutch = $key->getTranslation('nl');
        self::assertNotNull($dutch);
        self::getTranslationManager()->saveValue($dutch, '{count, plural, one {# artikel');

        // The flag decides how *every* language is rendered, so a message the intl parser
        // chokes on in Dutch has to block the switch even when the editor is looking at
        // English.
        $this->expectException(InvalidIcuMessageException::class);

        self::getTranslationManager()->setIcu($key, true);
    }

    public function testSwitchingIcuOffIsAlwaysAllowed(): void
    {
        self::givenTranslation('icu.off', 'en', 'Plain text', icu: true);
        $key = self::requireKey('icu.off');

        // Nothing can break by moving a value out of the ICU domain.
        self::assertTrue(self::getTranslationManager()->setIcu($key, false));
        self::assertFalse($key->isIcu());
    }

    public function testAProtectedKeyCannotBeDeleted(): void
    {
        self::givenTranslation('protected.check', 'en', 'Important');
        $key = self::requireKey('protected.check');
        $key->setProtected(true);
        self::getKeyRepository()->save($key);

        $this->expectException(TranslationWriteNotAllowedException::class);

        self::getTranslationManager()->deleteKey($key);
    }

    public function testDeletingAKeyRemovesItsValues(): void
    {
        self::givenTranslation('delete.check', 'en', 'Gone soon');
        $key = self::requireKey('delete.check');

        self::getTranslationManager()->deleteKey($key);

        self::assertNull(self::findKey('delete.check'));
    }

    public function testSearchAndReplaceCountsBeforeItCommits(): void
    {
        self::givenTranslation('sr.one', 'en', 'the old name is here');
        self::givenTranslation('sr.two', 'en', 'and the old name again');
        self::givenTranslation('sr.three', 'en', 'nothing to see');

        self::assertSame(2, self::getTranslationManager()->searchAndReplace('en', 'old name', 'new name', null, true));
        self::assertSame('the old name is here', self::valueOf('sr.one', 'en'));

        self::assertSame(2, self::getTranslationManager()->searchAndReplace('en', 'old name', 'new name'));
        self::assertSame('the new name is here', self::valueOf('sr.one', 'en'));
    }

    public function testCopyLocaleOnlyFillsWhatIsEmpty(): void
    {
        self::givenTranslation('copy.one', 'en', 'English one');
        self::givenTranslation('copy.two', 'en', 'English two');

        $existing = self::requireKey('copy.two')->getTranslation('nl');
        self::assertNotNull($existing);
        self::getTranslationManager()->saveValue($existing, 'Al vertaald');

        self::assertSame(1, self::getTranslationManager()->copyLocale('en', 'nl'));

        self::assertSame('English one', self::valueOf('copy.one', 'nl'));
        self::assertSame('Al vertaald', self::valueOf('copy.two', 'nl'));
    }
}

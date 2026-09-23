<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Unit\Domain\Model;

use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationSource;
use PHPUnit\Framework\TestCase;

class TranslationKeyTest extends TestCase
{
    public function testTheHashIsStableAndDistinct(): void
    {
        self::assertSame(TranslationKey::hash('a.b'), TranslationKey::hash('a.b'));
        self::assertNotSame(TranslationKey::hash('a.b'), TranslationKey::hash('a.c'));
        self::assertSame(64, \strlen(TranslationKey::hash('a.b')));
    }

    public function testASentenceKeyIsFine(): void
    {
        // Projects that use English sentences as keys are why the key column is TEXT and the
        // unique index is on the hash.
        $key = new TranslationKey('messages', \str_repeat('Your order has been shipped. ', 40));

        self::assertSame(64, \strlen($key->getKeyHash()));
    }

    public function testTheCatalogueDomainSplitsOffIcu(): void
    {
        $key = new TranslationKey('messages', 'items');

        self::assertSame('messages', $key->getCatalogueDomain());

        $key->setIcu(true);

        // Symfony keeps ICU messages in a domain of their own; an editor sees the real domain
        // and a flag.
        self::assertSame('messages+intl-icu', $key->getCatalogueDomain());
    }

    public function testATranslationIsFoundByLocaleBeforeItIsFlushed(): void
    {
        $key = new TranslationKey('messages', 'greeting', TranslationSource::Manual);
        $translation = new Translation($key, 'nl', 'hallo');

        // Doctrine's indexBy only applies to a collection it hydrated, so a key created in
        // this unit of work has to answer from the plain collection as well.
        self::assertSame($translation, $key->getTranslation('nl'));
        self::assertNull($key->getTranslation('fr'));
    }

    public function testAddingTheSameLocaleTwiceIsANoop(): void
    {
        $key = new TranslationKey('messages', 'greeting');
        $first = new Translation($key, 'nl', 'hallo');
        new Translation($key, 'nl', 'other');

        self::assertCount(1, $key->getTranslations());
        self::assertSame($first, $key->getTranslation('nl'));
    }

    public function testAnEmptyStringIsStoredAsNull(): void
    {
        $key = new TranslationKey('messages', 'greeting');
        $translation = new Translation($key, 'nl', '');

        // "Not translated" has exactly one representation, which is what makes the
        // untranslated filter a single IS NULL.
        self::assertNull($translation->getValue());
        self::assertFalse($translation->isTranslated());
    }
}

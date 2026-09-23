<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Locale\LocaleProvider;

/**
 * A value row exists for every locale from the moment the key does. Everything the admin can
 * do - the language switcher, the "not translated" filter, one row per key - depends on it.
 */
class LocaleMaterialisationTest extends TranslationsTestCase
{
    public function testTheLocalesComeFromTheWebspaces(): void
    {
        /** @var LocaleProvider $provider */
        $provider = self::getContainer()->get(LocaleProvider::class);

        self::assertSame(['de', 'en', 'fr', 'nl'], $provider->getLocales());
        self::assertSame('en', $provider->getDefaultLocale());
    }

    public function testANewKeyGetsARowPerLocale(): void
    {
        self::givenTranslation('materialise.check', 'en', 'Something');

        $key = self::requireKey('materialise.check');
        self::assertCount(4, $key->getTranslations());

        foreach (['de', 'en', 'fr', 'nl'] as $locale) {
            self::assertNotNull($key->getTranslation($locale), \sprintf('No row for "%s".', $locale));
        }
    }

    public function testAnUntranslatedRowIsEmptyAndSaysSo(): void
    {
        self::givenTranslation('materialise.empty', 'en', 'Only English');

        $french = self::requireKey('materialise.empty')->getTranslation('fr');

        self::assertNotNull($french);
        self::assertNull($french->getValue());
        self::assertFalse($french->isTranslated());
    }

    public function testSynchronisingIsIdempotent(): void
    {
        self::givenTranslation('materialise.idempotent', 'en', 'Something');

        self::assertSame(0, self::getSynchronizer()->synchroniseLocales());
        self::assertSame(0, self::getSynchronizer()->synchroniseLocales());

        self::assertCount(4, self::requireKey('materialise.idempotent')->getTranslations());
    }

    public function testSynchronisingFillsInAMissingLocale(): void
    {
        self::givenTranslation('materialise.gap', 'en', 'Something');

        $key = self::requireKey('materialise.gap');

        // Simulate the state a project is in the moment a webspace gains a language: keys
        // exist, one locale has no rows.
        self::getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM tl_translation WHERE locale = :locale',
            ['locale' => 'de'],
        );
        self::getEntityManager()->clear();

        self::assertSame(1, self::getSynchronizer()->synchroniseLocales());
        self::assertNotNull(self::requireKey('materialise.gap')->getTranslation('de'));
    }
}

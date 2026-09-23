<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bundle's premise: a value saved in the admin is what `trans()` returns, immediately,
 * with no cache clear anywhere.
 */
class CatalogueRuntimeTest extends TranslationsTestCase
{
    public function testTheDatabaseOverridesTheFile(): void
    {
        // `homepage.title` exists in tests/Application/translations/messages.en.yaml.
        self::assertSame('From the file, in English', $this->translator()->trans('homepage.title', [], 'messages', 'en'));

        self::givenTranslation('homepage.title', 'en', 'From the database');

        self::assertSame('From the database', $this->translator()->trans('homepage.title', [], 'messages', 'en'));
    }

    public function testAKeyOnlyInTheFileKeepsWorking(): void
    {
        self::givenTranslation('something.else', 'en', 'Whatever');

        self::assertSame('Only the file has this', $this->translator()->trans('homepage.only_in_file', [], 'messages', 'en'));
    }

    public function testTheWriteIsVisibleWithinTheSameRequest(): void
    {
        self::givenTranslation('live.check', 'en', 'first');
        self::assertSame('first', $this->translator()->trans('live.check', [], 'messages', 'en'));

        $translation = self::requireKey('live.check')->getTranslation('en');
        self::assertNotNull($translation);

        self::getTranslationManager()->saveValue($translation, 'second');

        // Only true because the write path bumps the version and drops the in-process memo.
        // It fails silently in workers and commands when that stops happening.
        self::assertSame('second', $this->translator()->trans('live.check', [], 'messages', 'en'));
    }

    public function testTheFallbackLocaleReachesTheDatabase(): void
    {
        self::givenTranslation('fallback.check', 'en', 'English from the database');

        // fr has no value at all; the configured fallback is en.
        self::assertSame('English from the database', $this->translator()->trans('fallback.check', [], 'messages', 'fr'));
    }

    public function testAnEmptyValueDoesNotShadowTheFile(): void
    {
        self::givenTranslation('homepage.title', 'en', null);

        // A key row exists for every locale from the moment the key does, and an empty one may
        // never hide what the file says.
        self::assertSame('From the file, in English', $this->translator()->trans('homepage.title', [], 'messages', 'en'));
    }

    public function testAnIcuValueIsFormattedAsIcu(): void
    {
        if (!\class_exists(\MessageFormatter::class)) {
            self::markTestSkipped('The intl extension is required.');
        }

        self::givenTranslation('cart.items', 'en', '{count, plural, one {# item} other {# items}}', icu: true);

        self::assertSame('1 item', $this->translator()->trans('cart.items', ['count' => 1], 'messages', 'en'));
        self::assertSame('7 items', $this->translator()->trans('cart.items', ['count' => 7], 'messages', 'en'));
    }

    public function testTheAdminDomainCanBeOverridden(): void
    {
        // A documented feature: rename a Sulu backend label for a client without forking
        // anything. It works because nothing in the mechanism is specific to `messages`.
        self::givenTranslation('sulu_admin.save', 'en', 'Store it', 'admin');

        self::assertSame('Store it', $this->translator()->trans('sulu_admin.save', [], 'admin', 'en'));
    }

    private function translator(): TranslatorInterface
    {
        /** @var TranslatorInterface $translator */
        $translator = static::getContainer()->get('translator');

        return $translator;
    }
}

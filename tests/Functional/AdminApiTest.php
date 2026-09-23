<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

/**
 * The endpoints the admin talks to.
 *
 * The REST id is the **key** id, never the per-locale value id: that is what lets the language
 * switcher keep the same URL and the same resource while only the value changes.
 */
class AdminApiTest extends TranslationsTestCase
{
    public function testTheListReturnsOneRowPerKeyInTheRequestedLocale(): void
    {
        self::givenTranslation('list.one', 'en', 'English one');
        self::givenTranslation('list.two', 'en', 'English two');
        $dutch = self::requireKey('list.one')->getTranslation('nl');
        self::assertNotNull($dutch);
        self::getTranslationManager()->saveValue($dutch, 'Nederlands een');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=nl&limit=50');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(2, $response['total']);

        $rows = [];

        foreach ($response['_embedded']['translation_keys'] as $row) {
            $rows[$row['translationKey']] = $row;
        }

        self::assertSame('Nederlands een', $rows['list.one']['value']);
        // A key with nothing in this language is still a row. That is the "what still needs
        // translating" screen.
        self::assertNull($rows['list.two']['value']);
        self::assertFalse($rows['list.two']['translated']);
        self::assertTrue($rows['list.one']['translated']);
    }

    public function testTheListCanBeNarrowedToWhatIsNotTranslated(): void
    {
        self::givenTranslation('untranslated.one', 'en', 'English');
        self::givenTranslation('untranslated.two', 'en', 'English');
        $dutch = self::requireKey('untranslated.one')->getTranslation('nl');
        self::assertNotNull($dutch);
        self::getTranslationManager()->saveValue($dutch, 'Nederlands');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=nl&untranslated=1');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(1, $response['total']);
        self::assertSame('untranslated.two', $response['_embedded']['translation_keys'][0]['translationKey']);
    }

    public function testTheListCarriesTheReferenceLocale(): void
    {
        self::givenTranslation('reference.check', 'en', 'The English one');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=fr&referenceLocale=en');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // A translator working in French needs the English next to them, and a second browser
        // tab is not an answer.
        self::assertSame('The English one', $response['_embedded']['translation_keys'][0]['referenceValue']);
    }

    public function testTheReferenceColumnIsEmptyWhenItPointsAtTheLocaleAlreadyShown(): void
    {
        self::givenTranslation('reference.same', 'en', 'The English one');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=en&referenceLocale=en');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // The list XML has to point `referenceValue` at a real column and points it at the
        // key's id, so a reference locale that is not merged in has to be emptied - otherwise
        // the column renders the row's id.
        self::assertNull($response['_embedded']['translation_keys'][0]['referenceValue']);
    }

    public function testGettingOneKeyInTwoLocales(): void
    {
        self::givenTranslation('detail.check', 'en', 'English value');
        $id = self::requireKey('detail.check')->getId();

        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $id . '?locale=en');
        $english = \json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $id . '?locale=fr');
        $french = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame($id, $english['id']);
        self::assertSame($id, $french['id']);
        self::assertSame('English value', $english['value']);
        self::assertNull($french['value']);
    }

    /**
     * Sulu's `Form.js` opens its "copy from another language" dialog whenever the current
     * locale is missing from `availableLocales`. That is right for a page and wrong for a
     * translation key, where "nothing in Dutch yet" is the normal state of most rows.
     *
     * Worse, a key with no value in *any* language sends `availableLocales: []`, which is
     * truthy, so the dialog opens with nothing to offer and its `single_select` renders
     * without a `values` option - React throws and the form never appears.
     */
    public function testThePayloadDoesNotTriggerSulusGhostDialog(): void
    {
        self::givenTranslation('ghost.nowhere', 'en', null);
        $id = self::requireKey('ghost.nowhere')->getId();

        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $id . '?locale=nl');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertArrayNotHasKey('availableLocales', $response);
        self::assertArrayNotHasKey('contentLocales', $response);
    }

    public function testTheDetailPayloadCarriesEverythingTheContextPanelShows(): void
    {
        $key = self::getSynchronizer()->ensureKey('messages', 'context.payload');
        $key->setOccurrences([
            ['path' => 'templates/base.html.twig', 'line' => 51],
            ['path' => 'src/Mailer.php', 'line' => 12],
        ]);
        $key->setLastUsedAt(new \DateTimeImmutable('2026-01-02 03:04:05'));
        self::getKeyRepository()->save($key);

        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $key->getId() . '?locale=en');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // Every line the panel renders comes from here. If one of these is missing the panel
        // shows a blank row and there is nothing in the browser to explain why.
        self::assertSame('context.payload', $response['translationKey']);
        self::assertSame('messages', $response['domain']);
        self::assertSame('manual', $response['source']);
        self::assertNotNull($response['lastUsedAt']);
        self::assertCount(2, $response['occurrences']);
        self::assertSame('templates/base.html.twig', $response['occurrences'][0]['path']);
        self::assertSame(51, $response['occurrences'][0]['line']);
    }

    /**
     * The form cannot be told which language a translator picked as their reference: that
     * choice lives in their browser, and Sulu carries only router attributes and fixed
     * parameters into a form request. So the payload ships every language and the field
     * picks one.
     *
     * Before this, the payload answered with the *source* locale alone - so a translator
     * editing English with the reference set to French saw no reference at all.
     */
    public function testTheDetailPayloadCarriesEveryLocalesValue(): void
    {
        self::givenTranslation('reference.all_locales', 'en', 'English value');
        $key = self::requireKey('reference.all_locales');
        $french = $key->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Valeur francaise');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $key->getId() . '?locale=en');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // Including the locale being edited, and including the ones that are still empty:
        // the field decides what to show, and it cannot decide on what it did not get.
        self::assertSame('English value', $response['values']['en']);
        self::assertSame('Valeur francaise', $response['values']['fr']);
        self::assertArrayHasKey('nl', $response['values']);
        self::assertNull($response['values']['nl']);
    }

    public function testCreatingAKey(): void
    {
        $this->client->jsonRequest('POST', '/admin/api/translation-keys?locale=en', [
            'translationKey' => 'created.by.hand',
            'domain' => 'messages',
            'value' => 'Typed in the admin',
        ]);

        self::assertResponseIsSuccessful();

        self::assertSame('Typed in the admin', self::valueOf('created.by.hand', 'en'));
        self::assertCount(4, self::requireKey('created.by.hand')->getTranslations());
    }

    public function testSavingAValue(): void
    {
        self::givenTranslation('put.check', 'en', 'Before');
        $id = self::requireKey('put.check')->getId();

        $this->client->jsonRequest('PUT', '/admin/api/translation-keys/' . $id . '?locale=en', ['value' => 'After']);

        self::assertResponseIsSuccessful();

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('After', $response['value']);
        self::assertTrue($response['translated']);
    }

    public function testAddingAKeyThatAlreadyExistsIsRefusedAndChangesNothing(): void
    {
        self::givenTranslation('duplicate.key', 'en', 'The one that is already there');

        $this->client->jsonRequest('POST', '/admin/api/translation-keys?locale=en', [
            'translationKey' => 'duplicate.key',
            'domain' => 'messages',
            'value' => 'Typed into the add form',
        ]);

        // It used to answer 200 with the other key's id and quietly replace its English,
        // which is the worst of the three possible outcomes: the editor believes they added
        // something and an existing translation is gone.
        self::assertResponseStatusCodeSame(409);

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertStringContainsString('already exists', $response['detail']);
        self::assertSame($response['message'], $response['detail']);

        self::getEntityManager()->clear();
        self::assertSame('The one that is already there', self::valueOf('duplicate.key', 'en'));
        self::assertSame(1, self::getKeyRepository()->countAll());
    }

    public function testAKeyWithNoDomainLandsInMessages(): void
    {
        // The add form no longer marks the domain mandatory, because Sulu ignores
        // `default_value` on a `text_line` and the field came up empty - so every add ended
        // in "The form contains invalid values" with nothing saying which field.
        $this->client->jsonRequest('POST', '/admin/api/translation-keys?locale=en', [
            'translationKey' => 'no.domain.given',
            'domain' => '',
            'value' => 'Fine without one',
        ]);

        self::assertResponseIsSuccessful();

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('messages', $response['domain']);
    }

    public function testAPlaceholderMismatchIsRefusedWithAReadableError(): void
    {
        self::givenTranslation('checkout.greeting', 'en', 'Hello %name%');
        $id = self::requireKey('checkout.greeting')->getId();

        $this->client->jsonRequest('PUT', '/admin/api/translation-keys/' . $id . '?locale=fr', ['value' => 'Bonjour']);

        // 422 and not 400: the request was understood, the content was refused. The inline
        // editor renders this on the cell.
        self::assertResponseStatusCodeSame(422);

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['name'], $response['context']['missing']);

        // `detail` is the sentence, because that is the field Sulu's form view renders. A
        // body with the structured data in there pushes an object into React, and a body
        // without it at all reaches the editor as "There was an error when trying to save the
        // form" - which says nothing about the placeholder they just dropped.
        self::assertSame($response['message'], $response['detail']);
    }

    public function testThePlaceholdersTravelWithEveryRowAndEveryPayload(): void
    {
        self::givenTranslation('placeholders.shown', 'en', 'Hello %name%, you have {count} messages');

        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=fr&limit=50');

        self::assertResponseIsSuccessful();
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // Not a column - the cell marks a value that dropped one and the open editor lists
        // them, and neither can ask the server per row.
        self::assertSame(['%name%', '{count}'], $response['_embedded']['translation_keys'][0]['placeholders']);

        $id = self::requireKey('placeholders.shown')->getId();
        $this->client->jsonRequest('GET', '/admin/api/translation-keys/' . $id . '?locale=fr');

        self::assertResponseIsSuccessful();
        $detail = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // In the payload for everybody, not behind the admin right: knowing that this
        // sentence has to contain `%name%` is the translator's business more than anybody's.
        self::assertSame(['%name%', '{count}'], $detail['placeholders']);
    }

    public function testTakingAPlaceholderOutOfTheSourceIsRefusedWhileItIsInUse(): void
    {
        self::givenTranslation('source.shrinking', 'en', 'Hello %name%');
        $key = self::requireKey('source.shrinking');
        $french = $key->getTranslation('fr');
        self::assertNotNull($french);
        self::getTranslationManager()->saveValue($french, 'Bonjour %name%');

        $this->client->jsonRequest(
            'PUT',
            '/admin/api/translation-keys/' . $key->getId() . '?locale=en',
            ['value' => 'Hello'],
        );

        self::assertResponseStatusCodeSame(422);

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        // The admin turns these two into a sentence in the editor's own language, naming the
        // rows they have to go and fix first.
        self::assertSame(['%name%'], $response['context']['stillInUse']);
        self::assertSame(['fr'], $response['context']['locales']);
    }

    public function testAnUnknownLocaleIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/admin/api/translation-keys?locale=xx');

        self::assertResponseStatusCodeSame(400);
    }

    public function testDeletingAKey(): void
    {
        self::givenTranslation('delete.api', 'en', 'Gone');
        $id = self::requireKey('delete.api')->getId();

        $this->client->jsonRequest('DELETE', '/admin/api/translation-keys/' . $id);

        self::assertResponseStatusCodeSame(204);
        self::assertNull(self::findKey('delete.api'));
    }

    public function testDeletingAProtectedKeyIsRefused(): void
    {
        self::givenTranslation('protected.api', 'en', 'Important');
        $key = self::requireKey('protected.api');
        $key->setProtected(true);
        self::getKeyRepository()->save($key);

        $this->client->jsonRequest('DELETE', '/admin/api/translation-keys/' . $key->getId());

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull(self::findKey('protected.api'));
    }

    public function testTheVersionListAndRestore(): void
    {
        $translation = self::givenTranslation('versions.api', 'en', 'One');
        self::getTranslationManager()->saveValue($translation, 'Two');
        $keyId = self::requireKey('versions.api')->getId();

        $this->client->jsonRequest('GET', '/admin/api/translation-versions?locale=en&translationKeyId=' . $keyId);
        self::assertResponseIsSuccessful();

        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('One', $response['_embedded']['translation_versions'][0]['value']);

        $versionId = $response['_embedded']['translation_versions'][0]['id'];
        $this->client->jsonRequest('POST', '/admin/api/translation-versions/' . $versionId . '?action=restore');

        self::assertResponseIsSuccessful();
        self::assertSame('One', self::valueOf('versions.api', 'en'));
    }

    public function testSearchAndReplaceAnswersADryRunFirst(): void
    {
        self::givenTranslation('sr.api', 'en', 'the old name');

        $this->client->jsonRequest('POST', '/admin/api/translation-actions/search-replace', [
            'locale' => 'en',
            'search' => 'old name',
            'replace' => 'new name',
            'dryRun' => true,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(1, \json_decode((string) $this->client->getResponse()->getContent(), true)['count']);
        self::assertSame('the old name', self::valueOf('sr.api', 'en'));
    }

    public function testMachineTranslationSaysSoWhenItIsNotConfigured(): void
    {
        $this->client->jsonRequest('POST', '/admin/api/translation-actions/machine-translate', [
            'locale' => 'nl',
            'from' => 'en',
        ]);

        self::assertResponseStatusCodeSame(400);
    }
}

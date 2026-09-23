<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

/**
 * Paging, sorting and searching of the translation list.
 *
 * The list is a `DoctrineListBuilder` over the key table with a **left join to the value
 * table on a runtime locale parameter**. That join is the interesting part: it is what makes
 * one row per key in the selected language possible, and it is also exactly the kind of thing
 * that quietly breaks counting and paging. Hence a test per property rather than one happy
 * path.
 */
class AdminListPaginationTest extends TranslationsTestCase
{
    private const TOTAL = 25;

    protected function setUp(): void
    {
        parent::setUp();

        for ($index = 1; $index <= self::TOTAL; ++$index) {
            self::givenTranslation(\sprintf('paging.key_%02d', $index), 'en', \sprintf('Value %02d', $index));
        }
    }

    public function testTheFirstRequestWithoutAnyPagingParametersStillPaginates(): void
    {
        // The state the screen opens in: Sulu sends neither `page` nor `limit` on the very
        // first request. If the answer to that one says there is a single page, the "next"
        // button is disabled before the user ever touches it - which looks exactly like
        // "pagination does not work until I put a page in the URL".
        $response = $this->list(['locale' => 'en']);

        self::assertSame(self::TOTAL, $response['total']);
        self::assertSame(1, $response['page']);
        self::assertGreaterThan(1, $response['pages'], 'The first request reported a single page for 25 rows.');
        self::assertLessThan(self::TOTAL, \count($response['_embedded']['translation_keys']));
    }

    public function testTheFirstPageReportsTheRealTotal(): void
    {
        $response = $this->list(['locale' => 'en', 'page' => 1, 'limit' => 10]);

        // The left join must not multiply or swallow rows in the count query.
        self::assertSame(self::TOTAL, $response['total']);
        self::assertSame(1, $response['page']);
        self::assertSame(3, $response['pages']);
        self::assertSame(10, $response['limit']);
        self::assertCount(10, $response['_embedded']['translation_keys']);
    }

    public function testEveryPageReturnsDifferentRows(): void
    {
        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->list(['locale' => 'en', 'page' => $page, 'limit' => 10, 'sortBy' => 'translationKey', 'sortOrder' => 'asc']);

            foreach ($response['_embedded']['translation_keys'] as $row) {
                $seen[] = $row['translationKey'];
            }
        }

        self::assertCount(self::TOTAL, $seen);
        self::assertCount(self::TOTAL, \array_unique($seen), 'A key appeared on more than one page.');
    }

    public function testEveryPageReturnsDifferentRowsWithoutAnExplicitSort(): void
    {
        // The state the screen actually opens in. Without a deterministic default order the
        // database is free to hand back overlapping rows per page, which looks exactly like
        // "pagination is broken" and is invisible in a test that sorts explicitly.
        $seen = [];

        foreach ([1, 2, 3] as $page) {
            foreach ($this->list(['locale' => 'en', 'page' => $page, 'limit' => 10])['_embedded']['translation_keys'] as $row) {
                $seen[] = $row['translationKey'];
            }
        }

        self::assertCount(self::TOTAL, $seen);
        self::assertCount(self::TOTAL, \array_unique($seen), 'A key appeared on more than one page.');
    }

    public function testTheLastPageIsNotPadded(): void
    {
        $response = $this->list(['locale' => 'en', 'page' => 3, 'limit' => 10]);

        self::assertCount(5, $response['_embedded']['translation_keys']);
        self::assertSame(self::TOTAL, $response['total']);
    }

    public function testPagingSurvivesTheReferenceLocale(): void
    {
        // The reference column is merged in after the list builder ran; it must not disturb
        // the page it was handed.
        $response = $this->list(['locale' => 'nl', 'page' => 2, 'limit' => 10, 'referenceLocale' => 'en']);

        self::assertCount(10, $response['_embedded']['translation_keys']);
        self::assertSame(self::TOTAL, $response['total']);
        self::assertNotNull($response['_embedded']['translation_keys'][0]['referenceValue']);
    }

    public function testPagingSurvivesTheUntranslatedFilter(): void
    {
        // Everything is translated in English and nothing in Dutch, so the filter has to
        // change both the total and the number of pages.
        $translated = $this->list(['locale' => 'en', 'page' => 1, 'limit' => 10, 'untranslated' => 1]);
        self::assertSame(0, $translated['total']);

        $untranslated = $this->list(['locale' => 'nl', 'page' => 1, 'limit' => 10, 'untranslated' => 1]);
        self::assertSame(self::TOTAL, $untranslated['total']);
        self::assertCount(10, $untranslated['_embedded']['translation_keys']);
    }

    public function testPagingSurvivesASearch(): void
    {
        // The keys are zero padded, so `key_1` matches key_10 to key_19: ten rows exactly,
        // which is one full page and no second one.
        $response = $this->list(['locale' => 'en', 'page' => 1, 'limit' => 10, 'search' => 'paging.key_1']);

        self::assertSame(10, $response['total']);
        self::assertSame(1, $response['pages']);
        self::assertCount(10, $response['_embedded']['translation_keys']);

        // And a narrower search really does narrow it, rather than the search being ignored.
        $narrow = $this->list(['locale' => 'en', 'page' => 1, 'limit' => 10, 'search' => 'paging.key_07']);

        self::assertSame(1, $narrow['total']);
    }

    public function testSortingIsStableAcrossPages(): void
    {
        $first = $this->list(['locale' => 'en', 'page' => 1, 'limit' => 10, 'sortBy' => 'translationKey', 'sortOrder' => 'desc']);
        $last = $this->list(['locale' => 'en', 'page' => 3, 'limit' => 10, 'sortBy' => 'translationKey', 'sortOrder' => 'desc']);

        self::assertSame('paging.key_25', $first['_embedded']['translation_keys'][0]['translationKey']);
        self::assertSame('paging.key_01', \end($last['_embedded']['translation_keys'])['translationKey']);
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    private function list(array $query): array
    {
        $this->client->jsonRequest('GET', '/admin/api/translation-keys?' . \http_build_query($query));

        self::assertResponseIsSuccessful();

        /** @var array<string, mixed> $response */
        $response = \json_decode((string) $this->client->getResponse()->getContent(), true);

        return $response;
    }
}

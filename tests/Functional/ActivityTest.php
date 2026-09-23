<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Tests\Functional;

use Eekes\SuluTranslationsBundle\Application\Io\FileImporter;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;

/**
 * The activity trail. One entry per value change, and exactly one for a whole run.
 */
class ActivityTest extends TranslationsTestCase
{
    public function testAChangeIsRecordedOnce(): void
    {
        $translation = self::givenTranslation('activity.check', 'en', 'One');
        self::getTranslationManager()->saveValue($translation, 'Two');

        $activities = $this->activities();

        self::assertCount(2, \array_filter($activities, static fn (array $row): bool => 'modified' === $row['type']));

        $modified = \array_values(\array_filter($activities, static fn (array $row): bool => 'modified' === $row['type']));
        $payload = \json_decode((string) $modified[1]['payload'], true);

        // The payload carries both values, so the list can show the change without a join and
        // the history survives the row being deleted.
        self::assertSame('One', $payload['from']);
        self::assertSame('Two', $payload['to']);
        self::assertSame('en', $modified[1]['resourceLocale']);
    }

    public function testANoOpSaveIsNotRecorded(): void
    {
        $translation = self::givenTranslation('activity.noop', 'en', 'Same');
        $before = \count($this->activities());

        self::getTranslationManager()->saveValue($translation, 'Same');

        // The inline editor and the form both send a PUT on blur. A trail full of no-op
        // entries is worse than no trail.
        self::assertCount($before, $this->activities());
    }

    public function testTheResourceIsTheKeyNotTheValue(): void
    {
        self::givenTranslation('activity.resource', 'en', 'Something');
        $key = self::requireKey('activity.resource');

        $activity = $this->activities()[0];

        self::assertSame(TranslationKey::RESOURCE_KEY, $activity['resourceKey']);
        self::assertSame((string) $key->getId(), $activity['resourceId']);
        self::assertSame('activity.resource', $activity['resourceTitle']);
        self::assertSame(TranslationKey::SECURITY_CONTEXT, $activity['resourceSecurityContext']);
    }

    public function testAnImportIsOneEntryNotThreeThousand(): void
    {
        /** @var FileImporter $importer */
        $importer = self::getContainer()->get(FileImporter::class);
        $importer->import([__DIR__ . '/../Application/translations']);

        $imported = \array_values(\array_filter($this->activities(), static fn (array $row): bool => 'imported' === $row['type']));

        self::assertCount(1, $imported);

        $payload = \json_decode((string) $imported[0]['payload'], true);
        self::assertGreaterThan(0, $payload['valuesCreated']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activities(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = self::getEntityManager()->getConnection()->executeQuery(
            'SELECT type, resourceKey, resourceId, resourceLocale, resourceTitle, resourceSecurityContext, payload
             FROM ac_activities ORDER BY id ASC',
        )->fetchAllAssociative();

        return $rows;
    }
}

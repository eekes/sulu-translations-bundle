<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Eekes\SuluTranslationsBundle\Domain\Model\CatalogueState;
use Eekes\SuluTranslationsBundle\Domain\Repository\CatalogueStateRepositoryInterface;

/**
 * Deliberately DBAL rather than ORM.
 *
 * `getVersion()` runs on every website request that renders a translated string, and it must
 * not be able to write, to hydrate an entity, or to be affected by a unit of work somebody else
 * left dirty. `bump()` is an UPDATE with an INSERT fallback for exactly the same reason: two
 * concurrent writes must not be able to lose each other's increment.
 */
class CatalogueStateRepository implements CatalogueStateRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getFingerprint(): string
    {
        /** @var array{token: string, version: int|string}|false $row */
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT token, version FROM tl_translation_state WHERE id = :id',
            ['id' => CatalogueState::ID],
        );

        // No row yet means nothing has ever been written, which is a perfectly good state and
        // not something a read should fix.
        return false === $row ? '' : $row['token'] . '.' . $row['version'];
    }

    public function bump(): string
    {
        $connection = $this->entityManager->getConnection();

        $updated = $connection->executeStatement(
            'UPDATE tl_translation_state SET version = version + 1, updated_at = :now WHERE id = :id',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => CatalogueState::ID],
        );

        if (0 === $updated) {
            try {
                $connection->insert('tl_translation_state', [
                    'id' => CatalogueState::ID,
                    'version' => 1,
                    'token' => CatalogueState::generateToken(),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                // Another process created the row between the UPDATE and the INSERT. Its
                // increment counts as ours: any change of the fingerprint invalidates the
                // caches.
                return $this->getFingerprint();
            }
        }

        return $this->getFingerprint();
    }
}

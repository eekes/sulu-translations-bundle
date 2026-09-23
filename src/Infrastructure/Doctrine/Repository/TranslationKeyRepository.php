<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationKeyRepositoryInterface;

/**
 * @extends EntityRepository<TranslationKey>
 */
class TranslationKeyRepository extends EntityRepository implements TranslationKeyRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(TranslationKey::class));
    }

    public function findById(int $id): ?TranslationKey
    {
        return $this->find($id);
    }

    public function findOneByKey(string $domain, string $key): ?TranslationKey
    {
        return $this->findOneBy(['domain' => $domain, 'keyHash' => TranslationKey::hash($key)]);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<TranslationKey> $keys */
        $keys = $this->createQueryBuilder('k')
            ->andWhere('k.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $keys;
    }

    public function findForPlaceholderSync(bool $onlyUndeclared = true): array
    {
        $builder = $this->createQueryBuilder('k')->orderBy('k.id', 'ASC');

        if ($onlyUndeclared) {
            $builder->andWhere('k.placeholders IS NULL');
        }

        /** @var list<TranslationKey> $keys */
        $keys = $builder->getQuery()->getResult();

        return $keys;
    }

    public function save(TranslationKey $key, bool $flush = true): void
    {
        $this->getEntityManager()->persist($key);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TranslationKey $key, bool $flush = true): void
    {
        $this->getEntityManager()->remove($key);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findDomains(): array
    {
        /** @var list<array{domain: string}> $rows */
        $rows = $this->createQueryBuilder('k')
            ->select('DISTINCT k.domain AS domain')
            ->orderBy('k.domain', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return \array_column($rows, 'domain');
    }

    public function findUnused(array $seenKeyHashes, \DateTimeImmutable $usedSince): array
    {
        $seen = \array_flip($seenKeyHashes);

        /** @var list<TranslationKey> $candidates */
        $candidates = $this->createQueryBuilder('k')
            ->andWhere('k.lastUsedAt IS NULL OR k.lastUsedAt < :usedSince')
            ->setParameter('usedSince', $usedSince)
            ->orderBy('k.domain', 'ASC')
            ->addOrderBy('k.id', 'ASC')
            ->getQuery()
            ->getResult();

        return \array_values(\array_filter(
            $candidates,
            static fn (TranslationKey $key): bool => !isset($seen[$key->getDomain() . "\0" . $key->getKeyHash()]),
        ));
    }

    public function findOrphanedBefore(\DateTimeImmutable $orphanedBefore, bool $includeProtected = false): array
    {
        $queryBuilder = $this->createQueryBuilder('k')
            ->andWhere('k.orphanedAt IS NOT NULL')
            ->andWhere('k.orphanedAt < :before')
            ->setParameter('before', $orphanedBefore)
            ->orderBy('k.orphanedAt', 'ASC');

        if (!$includeProtected) {
            $queryBuilder->andWhere('k.protected = false');
        }

        /** @var list<TranslationKey> $keys */
        $keys = $queryBuilder->getQuery()->getResult();

        return $keys;
    }

    public function touchLastUsed(array $keyHashesByDomain, \DateTimeImmutable $threshold): void
    {
        if ([] === $keyHashesByDomain) {
            return;
        }

        $connection = $this->getEntityManager()->getConnection();
        $platform = $connection->getDatabasePlatform();

        $conditions = [];
        $parameters = [
            'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'threshold' => $threshold->format('Y-m-d H:i:s'),
        ];
        $index = 0;

        foreach ($keyHashesByDomain as $pair) {
            [$domain, $hash] = \explode("\0", $pair, 2);
            $conditions[] = \sprintf('(domain = :d%1$d AND key_hash = :h%1$d)', $index);
            $parameters['d' . $index] = $domain;
            $parameters['h' . $index] = $hash;
            ++$index;
        }

        // One statement, even for two hundred keys: this runs on kernel.terminate of an
        // ordinary website request.
        $connection->executeStatement(
            \sprintf(
                'UPDATE %s SET last_used_at = :now WHERE (last_used_at IS NULL OR last_used_at < :threshold) AND (%s)',
                $platform->quoteIdentifier('tl_translation_key'),
                \implode(' OR ', $conditions),
            ),
            $parameters,
        );
    }

    public function countTranslated(string $locale): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Translation::class, 't')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.translated = true')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('k')
            ->select('COUNT(k.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}

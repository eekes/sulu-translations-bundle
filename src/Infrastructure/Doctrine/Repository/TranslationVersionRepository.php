<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationVersion;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationVersionRepositoryInterface;

/**
 * @extends EntityRepository<TranslationVersion>
 */
class TranslationVersionRepository extends EntityRepository implements TranslationVersionRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(TranslationVersion::class));
    }

    public function findById(int $id): ?TranslationVersion
    {
        return $this->find($id);
    }

    public function findByTranslation(Translation $translation, int $limit = 50, int $offset = 0): array
    {
        /** @var list<TranslationVersion> $versions */
        $versions = $this->createQueryBuilder('v')
            ->andWhere('v.translation = :translation')
            ->setParameter('translation', $translation)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $versions;
    }

    public function countByTranslation(Translation $translation): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->andWhere('v.translation = :translation')
            ->setParameter('translation', $translation)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function prune(Translation $translation, int $keep): int
    {
        if ($keep < 0) {
            return 0;
        }

        /** @var list<array{id: int}> $survivors */
        $survivors = $this->createQueryBuilder('v')
            ->select('v.id AS id')
            ->andWhere('v.translation = :translation')
            ->setParameter('translation', $translation)
            ->orderBy('v.createdAt', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->setMaxResults($keep)
            ->getQuery()
            ->getArrayResult();

        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->delete(TranslationVersion::class, 'v')
            ->andWhere('v.translation = :translation')
            ->setParameter('translation', $translation);

        if ([] !== $survivors) {
            $queryBuilder->andWhere('v.id NOT IN (:survivors)')
                ->setParameter('survivors', \array_column($survivors, 'id'));
        }

        return (int) $queryBuilder->getQuery()->execute();
    }
}

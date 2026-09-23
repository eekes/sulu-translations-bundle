<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Doctrine\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Eekes\SuluTranslationsBundle\Domain\Model\Translation;
use Eekes\SuluTranslationsBundle\Domain\Model\TranslationKey;
use Eekes\SuluTranslationsBundle\Domain\Repository\TranslationRepositoryInterface;

/**
 * @extends EntityRepository<Translation>
 */
class TranslationRepository extends EntityRepository implements TranslationRepositoryInterface
{
    public function __construct(EntityManagerInterface $entityManager)
    {
        parent::__construct($entityManager, $entityManager->getClassMetadata(Translation::class));
    }

    public function findById(int $id): ?Translation
    {
        return $this->find($id);
    }

    public function findOneByKeyAndLocale(TranslationKey $key, string $locale): ?Translation
    {
        return $this->findOneBy(['translationKey' => $key, 'locale' => $locale]);
    }

    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Translation> $translations */
        $translations = $this->createQueryBuilder('t')
            ->addSelect('k')
            ->innerJoin('t.translationKey', 'k')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        return $translations;
    }

    public function save(Translation $translation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($translation);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function loadCatalogue(string $locale): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT k.domain AS domain, k.translation_key AS translation_key, k.icu AS icu, t.value AS value
             FROM tl_translation t
             INNER JOIN tl_translation_key k ON k.id = t.translation_key_id
             WHERE t.locale = :locale AND t.value IS NOT NULL AND t.value <> \'\'',
            ['locale' => $locale],
        )->fetchAllAssociative();

        $catalogue = [];

        foreach ($rows as $row) {
            /** @var array{domain: string, translation_key: string, icu: int|bool|string, value: string} $row */
            $domain = (bool) $row['icu'] ? $row['domain'] . '+intl-icu' : $row['domain'];
            $catalogue[$domain][$row['translation_key']] = $row['value'];
        }

        return $catalogue;
    }

    public function findForMigration(array $locales = [], ?string $domain = null): array
    {
        $sql = 'SELECT k.domain AS domain, k.translation_key AS translation_key, k.key_hash AS key_hash,
                       k.icu AS icu, t.locale AS locale, t.value AS value,
                       t.migrated_at AS migrated_at, t.changed AS changed
                FROM tl_translation t
                INNER JOIN tl_translation_key k ON k.id = t.translation_key_id
                WHERE t.value IS NOT NULL AND t.value <> \'\'';

        $parameters = [];
        $types = [];

        if ([] !== $locales) {
            $sql .= ' AND t.locale IN (:locales)';
            $parameters['locales'] = $locales;
            $types['locales'] = ArrayParameterType::STRING;
        }

        if (null !== $domain) {
            $sql .= ' AND k.domain = :domain';
            $parameters['domain'] = $domain;
        }

        $sql .= ' ORDER BY k.domain, k.translation_key, t.locale';

        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            /** @var array{domain: string, translation_key: string, key_hash: string, icu: int|bool|string, locale: string, value: string, migrated_at: string|null, changed: string|null} $row */
            $migratedAt = $this->toDateTime($row['migrated_at']);
            $changed = $this->toDateTime($row['changed']);

            $result[] = [
                'domain' => $row['domain'],
                'key' => $row['translation_key'],
                'hash' => $row['key_hash'],
                'icu' => (bool) $row['icu'],
                'locale' => $row['locale'],
                'value' => $row['value'],
                'shipped' => null !== $migratedAt,
                // Both sides are second-precision, so a value the migration itself wrote -
                // `changed` and `migrated_at` set from the same timestamp - is equal, not
                // greater, and does not read as edited.
                'changedSinceShipped' => null !== $migratedAt && null !== $changed && $changed > $migratedAt,
            ];
        }

        return $result;
    }

    public function materialiseLocales(array $locales): int
    {
        if ([] === $locales) {
            return 0;
        }

        $connection = $this->getEntityManager()->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $created = 0;

        foreach ($locales as $locale) {
            // `created` and `changed` are added to the mapping by Sulu's TimestampableSubscriber
            // and are NOT NULL, so a raw insert has to fill them.
            $created += (int) $connection->executeStatement(
                'INSERT INTO tl_translation (translation_key_id, locale, value, is_translated, created, changed)
                 SELECT k.id, :locale, NULL, 0, :now, :now
                 FROM tl_translation_key k
                 LEFT JOIN tl_translation t ON t.translation_key_id = k.id AND t.locale = :locale
                 WHERE t.id IS NULL',
                ['locale' => $locale, 'now' => $now],
            );
        }

        if ($created > 0) {
            // Rows were inserted behind the ORM's back; anything already in memory is stale.
            $this->getEntityManager()->clear();
        }

        return $created;
    }

    public function findUntranslated(string $sourceLocale, string $targetLocale, ?string $domain = null, array $onlyKeyIds = [], ?int $limit = null, bool $onlyEmptyTarget = true): array
    {
        $sql = 'SELECT target.id AS id, k.id AS key_id, k.translation_key AS translation_key,
                       k.domain AS domain, k.icu AS icu, source.value AS source_value
                FROM tl_translation target
                INNER JOIN tl_translation_key k ON k.id = target.translation_key_id
                INNER JOIN tl_translation source ON source.translation_key_id = k.id AND source.locale = :sourceLocale
                WHERE target.locale = :targetLocale
                  AND source.value IS NOT NULL AND source.value <> \'\'';

        if ($onlyEmptyTarget) {
            $sql .= ' AND (target.value IS NULL OR target.value = \'\')';
        }

        $parameters = ['sourceLocale' => $sourceLocale, 'targetLocale' => $targetLocale];

        if (null !== $domain) {
            $sql .= ' AND k.domain = :domain';
            $parameters['domain'] = $domain;
        }

        if ([] !== $onlyKeyIds) {
            $sql .= ' AND k.id IN (:keyIds)';
            $parameters['keyIds'] = $onlyKeyIds;
        }

        $sql .= ' ORDER BY k.domain ASC, k.id ASC';

        if (null !== $limit) {
            $sql .= ' LIMIT ' . $limit;
        }

        $types = [] !== $onlyKeyIds ? ['keyIds' => ArrayParameterType::INTEGER] : [];

        /** @var list<array{id: int, key_id: int, translation_key: string, domain: string, icu: int|bool, source_value: string}> $rows */
        $rows = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $parameters, $types)
            ->fetchAllAssociative();

        return \array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'keyId' => (int) $row['key_id'],
                'key' => $row['translation_key'],
                'domain' => $row['domain'],
                'icu' => (bool) $row['icu'],
                'source' => $row['source_value'],
            ],
            $rows,
        );
    }

    public function findForSearchAndReplace(string $locale, string $search, ?string $domain = null): array
    {
        $queryBuilder = $this->createQueryBuilder('t')
            ->addSelect('k')
            ->innerJoin('t.translationKey', 'k')
            ->andWhere('t.locale = :locale')
            ->andWhere('t.value LIKE :search')
            ->setParameter('locale', $locale)
            ->setParameter('search', '%' . \addcslashes($search, '%_') . '%')
            ->orderBy('k.domain', 'ASC')
            ->addOrderBy('k.id', 'ASC');

        if (null !== $domain) {
            $queryBuilder->andWhere('k.domain = :domain')->setParameter('domain', $domain);
        }

        /** @var list<Translation> $translations */
        $translations = $queryBuilder->getQuery()->getResult();

        return $translations;
    }

    public function findLocales(): array
    {
        /** @var list<array{locale: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('DISTINCT t.locale AS locale')
            ->orderBy('t.locale', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return \array_column($rows, 'locale');
    }

    /**
     * The value of one key in another locale, for the reference column of the list. One query
     * for the whole page, keyed by key id.
     *
     * @param list<int> $keyIds
     *
     * @return array<int, string|null>
     */
    public function findValuesByKeyIds(array $keyIds, string $locale): array
    {
        if ([] === $keyIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT translation_key_id, value FROM tl_translation WHERE locale = :locale AND translation_key_id IN (:ids)',
            ['locale' => $locale, 'ids' => $keyIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $values = [];

        foreach ($rows as $row) {
            /** @var array{translation_key_id: int, value: string|null} $row */
            $values[(int) $row['translation_key_id']] = $row['value'];
        }

        return $values;
    }

    private function toDateTime(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

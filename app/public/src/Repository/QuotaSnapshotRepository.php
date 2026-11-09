<?php

namespace App\Repository;

use App\Entity\QuotaSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<QuotaSnapshot> */
class QuotaSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, QuotaSnapshot::class);
    }

    /**
     * The newest snapshot of every account.
     *
     * @return array<string, QuotaSnapshot> by auth index
     */
    public function latest(): array
    {
        // By when it was measured, not by when it was saved: a late fallback from old headers must not win.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.id FROM quota_snapshot s JOIN (SELECT auth_index, MAX(fetched_at) AS newest FROM quota_snapshot GROUP BY auth_index) m'
            .' ON m.auth_index = s.auth_index AND m.newest = s.fetched_at');
        if ([] === $rows) {
            return [];
        }
        $latest = [];
        foreach ($this->findBy(['id' => array_map('intval', array_column($rows, 'id'))], ['id' => 'ASC']) as $snapshot) {
            $latest[$snapshot->authIndex] = $snapshot;
        }

        return $latest;
    }

    /**
     * Every snapshot since a time, by account, oldest first; with the last one before it, so each account's value at
     * the start is known.
     *
     * @param list<string> $authIndexes
     *
     * @return array<string, list<QuotaSnapshot>>
     */
    public function history(array $authIndexes, \DateTimeImmutable $since, ?\DateTimeImmutable $until = null): array
    {
        if ([] === $authIndexes) {
            return [];
        }
        $history = array_fill_keys($authIndexes, []);
        $rows = $this->createQueryBuilder('s')
            ->where('s.authIndex IN (:accounts)')
            ->andWhere('s.fetchedAt >= :since')
            ->setParameter('accounts', $authIndexes)
            ->setParameter('since', $since->modify('-1 day'), 'utc_datetime_immutable')
            ->andWhere('s.fetchedAt <= :until')
            ->setParameter('until', $until ?? new \DateTimeImmutable('+1 day'), 'utc_datetime_immutable')
            ->orderBy('s.fetchedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($rows as $snapshot) {
            $history[$snapshot->authIndex][] = $snapshot;
        }

        return $history;
    }
}

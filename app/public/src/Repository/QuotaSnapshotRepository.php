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
        $newest = $this->createQueryBuilder('s')
            ->select('MAX(s.id)')
            ->groupBy('s.authIndex')
            ->getQuery()
            ->getSingleColumnResult();
        if ([] === $newest) {
            return [];
        }
        $latest = [];
        foreach ($this->findBy(['id' => $newest]) as $snapshot) {
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
    public function history(array $authIndexes, \DateTimeImmutable $since): array
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
            ->orderBy('s.fetchedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
        foreach ($rows as $snapshot) {
            $history[$snapshot->authIndex][] = $snapshot;
        }

        return $history;
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.fetchedAt < :before')
            ->setParameter('before', $before, 'utc_datetime_immutable')
            ->getQuery()
            ->execute();
    }
}

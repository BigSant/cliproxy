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

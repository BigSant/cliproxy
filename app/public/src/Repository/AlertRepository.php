<?php

namespace App\Repository;

use App\Entity\Alert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Alert> */
class AlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Alert::class);
    }

    /** @return list<Alert> errors first, then the newest */
    public function open(): array
    {
        $alerts = $this->findBy(['resolvedAt' => null], ['lastSeenAt' => 'DESC']);
        usort($alerts, static fn (Alert $a, Alert $b) => [Alert::ERROR !== $a->level, Alert::WARNING !== $a->level] <=> [Alert::ERROR !== $b->level, Alert::WARNING !== $b->level]);

        return $alerts;
    }

    /** @return list<Alert> */
    public function resolved(int $limit): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.resolvedAt IS NOT NULL')
            ->orderBy('a.resolvedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

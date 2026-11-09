<?php

namespace App\Repository;

use App\Entity\ToolCall;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ToolCall> */
class ToolCallRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ToolCall::class);
    }

    /**
     * Calls still waiting for their result.
     *
     * @param list<string> $callIds
     *
     * @return list<ToolCall>
     */
    public function openByCallIds(array $callIds): array
    {
        if ([] === $callIds) {
            return [];
        }

        return $this->createQueryBuilder('c')
            ->where('c.callId IN (:ids)')
            ->andWhere('c.answered = false')
            ->setParameter('ids', $callIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Per tool: calls, failures, successes and calls whose result never said (or never came).
     *
     * @return list<array{name: string, calls: int, failed: int, succeeded: int, unknown: int}>
     */
    public function stats(\DateTimeImmutable $since, ?string $provider = null): array
    {
        $query = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('c.name, COUNT(*) AS calls',
                'SUM(CASE WHEN c.is_error = 1 THEN 1 ELSE 0 END) AS failed',
                'SUM(CASE WHEN c.is_error = 0 THEN 1 ELSE 0 END) AS succeeded')
            ->from('tool_call', 'c')
            ->innerJoin('c', 'exchange', 'e', 'e.id = c.exchange_id')
            ->where('e.occurred_at >= :since')
            ->andWhere("e.kind <> 'superseded'")
            ->setParameter('since', $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'))
            ->groupBy('c.name')
            ->orderBy('calls', 'DESC');
        if (null !== $provider) {
            $query->andWhere('e.provider = :provider')->setParameter('provider', $provider);
        }

        return array_map(static fn (array $row) => [
            'name' => (string) $row['name'],
            'calls' => (int) $row['calls'],
            'failed' => (int) $row['failed'],
            'succeeded' => (int) $row['succeeded'],
            'unknown' => (int) $row['calls'] - (int) $row['failed'] - (int) $row['succeeded'],
        ], $query->fetchAllAssociative());
    }

    /** @return list<ToolCall> the latest failed calls, newest first */
    public function recentFailures(\DateTimeImmutable $since, int $limit, ?string $name = null): array
    {
        $query = $this->createQueryBuilder('c')
            ->addSelect('e')
            ->innerJoin('c.exchange', 'e')
            ->where('c.isError = true')
            ->andWhere("e.kind <> 'superseded'")
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('since', $since, 'utc_datetime_immutable')
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults($limit);
        if (null !== $name) {
            $query->andWhere('c.name = :name')->setParameter('name', $name);
        }

        return $query->getQuery()->getResult();
    }
}

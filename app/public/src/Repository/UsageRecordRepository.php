<?php

namespace App\Repository;

use App\Entity\UsageRecord;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Usage sums for the dashboard. Plain SQL that MySQL (the hub) and SQLite (tests) both run; times are compared in UTC,
 * as stored.
 *
 * @extends ServiceEntityRepository<UsageRecord>
 */
class UsageRecordRepository extends ServiceEntityRepository
{
    private const string SUMS = 'COUNT(*) AS requests, SUM(CASE WHEN failed = 1 THEN 1 ELSE 0 END) AS failed,'
        .' SUM(input_tokens) AS input, SUM(output_tokens) AS output, SUM(cache_read_tokens) AS cache_read,'
        .' SUM(cache_creation_tokens) AS cache_creation, SUM(total_tokens) AS total';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageRecord::class);
    }

    /** @return array{requests: int, failed: int, input: int, output: int, cache_read: int, cache_creation: int, total: int} */
    public function totals(\DateTimeImmutable $since): array
    {
        $row = $this->since($since)->select(self::SUMS)->fetchAssociative() ?: [];

        return self::numbers($row);
    }

    /** @return list<array<string, int|string>> one row per account, busiest first */
    public function byAccount(\DateTimeImmutable $since): array
    {
        return $this->grouped($since, 'auth_index', 'auth_index, MAX(account) AS account, MAX(provider) AS provider, MAX(occurred_at) AS last_at');
    }

    /** @return list<array<string, int|string>> one row per model, busiest first */
    public function byModel(\DateTimeImmutable $since): array
    {
        return $this->grouped($since, 'model', 'model, MAX(provider) AS provider');
    }

    /** @return list<array<string, int|string>> one row per UTC day, oldest first */
    public function byDay(\DateTimeImmutable $since): array
    {
        $rows = $this->since($since)
            ->select('SUBSTR(occurred_at, 1, 10) AS day, '.self::SUMS)
            ->groupBy('SUBSTR(occurred_at, 1, 10)')
            ->orderBy('day')
            ->fetchAllAssociative();

        return array_map(static fn (array $row) => ['day' => (string) $row['day']] + self::numbers($row), $rows);
    }

    /**
     * Session affinity check: a session should stay on one account, because prompt caches belong to it. Sessions
     * served by more than one account pay cache writes again.
     *
     * @return array{sessions: int, moved: int, examples: list<array{session_id: string, accounts: int, requests: int}>}
     */
    public function affinity(\DateTimeImmutable $since): array
    {
        $perSession = $this->since($since)
            ->select('session_id, COUNT(DISTINCT auth_index) AS accounts, COUNT(*) AS requests')
            ->andWhere("session_id <> ''")
            ->groupBy('session_id')
            ->fetchAllAssociative();
        $moved = array_values(array_filter($perSession, static fn (array $row) => (int) $row['accounts'] > 1));
        usort($moved, static fn (array $a, array $b) => (int) $b['requests'] <=> (int) $a['requests']);

        return [
            'sessions' => \count($perSession),
            'moved' => \count($moved),
            'examples' => array_map(static fn (array $row) => [
                'session_id' => (string) $row['session_id'],
                'accounts' => (int) $row['accounts'],
                'requests' => (int) $row['requests'],
            ], \array_slice($moved, 0, 5)),
        ];
    }

    /** @return list<UsageRecord> newest first */
    public function recent(int $limit): array
    {
        return $this->findBy([], ['occurredAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /** The usage record of a request: the same request id ending, within a quarter of an hour. */
    public function forRequest(string $requestTail, \DateTimeImmutable $at): ?UsageRecord
    {
        if ('' === $requestTail) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('u.requestTail = :tail')
            ->andWhere('u.occurredAt BETWEEN :from AND :to')
            ->setParameter('tail', $requestTail)
            ->setParameter('from', $at->modify('-15 minutes'), 'utc_datetime_immutable')
            ->setParameter('to', $at->modify('+15 minutes'), 'utc_datetime_immutable')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->delete('usage_record')
            ->where('occurred_at < :before')
            ->setParameter('before', self::utc($before))
            ->executeStatement();
    }

    /** @return list<array<string, int|string>> */
    private function grouped(\DateTimeImmutable $since, string $group, string $columns): array
    {
        $rows = $this->since($since)
            ->select($columns.', '.self::SUMS)
            ->groupBy($group)
            ->orderBy('requests', 'DESC')
            ->fetchAllAssociative();

        return array_map(static function (array $row) {
            $labels = array_diff_key($row, self::numbers([]));

            return array_map('strval', $labels) + self::numbers($row);
        }, $rows);
    }

    private function since(\DateTimeImmutable $since): QueryBuilder
    {
        return $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->from('usage_record')
            ->where('occurred_at >= :since')
            ->setParameter('since', self::utc($since));
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{requests: int, failed: int, input: int, output: int, cache_read: int, cache_creation: int, total: int}
     */
    private static function numbers(array $row): array
    {
        $numbers = [];
        foreach (['requests', 'failed', 'input', 'output', 'cache_read', 'cache_creation', 'total'] as $key) {
            $numbers[$key] = (int) ($row[$key] ?? 0);
        }

        return $numbers;
    }
}

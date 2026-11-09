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
    // A failed attempt that another attempt of the same request followed is "superseded": requests are the records that
    // are not, failed requests the failed records that are not (the last attempt failed too). Attempts are all records.
    private const string SUMS = 'SUM(CASE WHEN superseded = 1 THEN 0 ELSE 1 END) AS requests,'
        .' COUNT(*) AS attempts, SUM(CASE WHEN failed = 1 AND superseded = 0 THEN 1 ELSE 0 END) AS failed,'
        .' SUM(input_tokens) AS input, SUM(output_tokens) AS output, SUM(cache_read_tokens) AS cache_read,'
        .' SUM(cache_creation_tokens) AS cache_creation, SUM(cache_creation5m_tokens) AS cache_5m,'
        .' SUM(cache_creation1h_tokens) AS cache_1h, SUM(total_tokens) AS total';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UsageRecord::class);
    }

    /** @return array{requests: int, failed: int, input: int, output: int, cache_read: int, cache_creation: int, cache_5m: int, cache_1h: int, total: int, attempts: int} */
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

    /**
     * One row per local day (Europe/Vilnius, PHP's zone), oldest first: summed per UTC hour in SQL, folded into days
     * here, so a day starts at local midnight.
     *
     * @return list<array<string, int|string>>
     */
    public function byDay(\DateTimeImmutable $since): array
    {
        $rows = $this->since($since)
            ->select('SUBSTR(occurred_at, 1, 13) AS hour, '.self::SUMS)
            ->groupBy('SUBSTR(occurred_at, 1, 13)')
            ->orderBy('hour')
            ->fetchAllAssociative();
        $zone = new \DateTimeZone(date_default_timezone_get());
        $days = [];
        foreach ($rows as $row) {
            $day = (new \DateTimeImmutable($row['hour'].':00:00', new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            $numbers = self::numbers($row);
            if (!isset($days[$day])) {
                $days[$day] = ['day' => $day] + $numbers;
                continue;
            }
            foreach ($numbers as $key => $value) {
                $days[$day][$key] += $value;
            }
        }

        return array_values($days);
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

    /**
     * @param list<string> $keys
     *
     * @return list<string> those already stored
     */
    public function existingKeys(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }

        return array_map('strval', $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('record_key')
            ->from('usage_record')
            ->where('record_key IN (:keys)')
            ->setParameter('keys', $keys, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->fetchFirstColumn());
    }

    /**
     * The attempts already stored of these requests.
     *
     * @param list<string> $requestIds
     *
     * @return list<array{request_id: string, at: float, failed: bool, id: int}>
     */
    public function attemptsOf(array $requestIds): array
    {
        $rows = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('id, request_id, occurred_at, occurred_micros, failed')
            ->from('usage_record')
            ->where('request_id IN (:ids)')
            ->setParameter('ids', $requestIds, \Doctrine\DBAL\ArrayParameterType::STRING)
            ->fetchAllAssociative();

        return array_map(static fn (array $row) => [
            'request_id' => (string) $row['request_id'],
            'at' => (new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC')))->getTimestamp() + (int) $row['occurred_micros'] / 1e6,
            'failed' => (bool) $row['failed'],
            'id' => (int) $row['id'],
        ], $rows);
    }

    /**
     * The newest User-Agent of a real client per provider: the limits requests then look like that client.
     *
     * @return array<string, string>
     */
    public function latestUserAgents(): array
    {
        $agents = [];
        foreach (['claude' => 'claude-cli/', 'codex' => 'codex'] as $provider => $prefix) {
            $agent = $this->getEntityManager()->getConnection()->createQueryBuilder()
                ->select('user_agent')
                ->from('usage_record')
                ->where('provider = :provider')
                ->andWhere('user_agent LIKE :prefix')
                ->setParameter('provider', $provider)
                ->setParameter('prefix', $prefix.'%')
                ->orderBy('id', 'DESC')
                ->setMaxResults(1)
                ->fetchOne();
            if (\is_string($agent) && '' !== $agent) {
                $agents[$provider] = $agent;
            }
        }

        return $agents;
    }

    /**
     * The usage record of a request: the same request id ending, between its start and three hours later (a long
     * stream finishes late); its last attempt, which is the one that answered.
     */
    public function forRequest(string $requestTail, \DateTimeImmutable $at): ?UsageRecord
    {
        if ('' === $requestTail) {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('u.requestTail = :tail')
            // A retried request's earlier attempts are not its answer.
            ->andWhere('u.superseded = false')
            ->andWhere('u.occurredAt BETWEEN :from AND :to')
            ->setParameter('tail', $requestTail)
            ->setParameter('from', $at->modify('-5 minutes'), 'utc_datetime_immutable')
            ->setParameter('to', $at->modify('+3 hours'), 'utc_datetime_immutable')
            ->orderBy('u.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A Codex WebSocket turn's usage record: the connection's request id (all its turns share it) or, without one, the
     * session. CLIProxyAPI stamps a record with its attempt's start, after the timeline's request, so a turn's records
     * lie from its start to the connection's next turn's start (exclusive, to the microsecond). Of those the last one
     * not linked yet, of the turn's model if there is one: after a switch to another account, the attempt that answered. Superseded attempts count too –
     * a failed turn the next one followed has only such a record.
     */
    public function forWebsocketTurn(string $requestTail, string $sessionId, \DateTimeImmutable $at, ?\DateTimeImmutable $nextTurnAt = null, string $model = ''): ?UsageRecord
    {
        $until = $at->modify('+30 minutes');
        if (null !== $nextTurnAt && $nextTurnAt < $until) {
            $until = $nextTurnAt;
        }
        // Whole seconds in SQL (the column keeps them), the microseconds below.
        $query = $this->createQueryBuilder('u')
            ->where('u.occurredAt BETWEEN :from AND :to')
            // Taken by a turn still in the journal; one a re-read replaced does not hold it.
            ->andWhere("NOT EXISTS (SELECT 1 FROM App\\Entity\\Exchange e WHERE e.usageRecordId = u.id AND e.kind <> 'superseded')")
            ->setParameter('from', $at->setTime((int) $at->format('H'), (int) $at->format('i'), (int) $at->format('s')), 'utc_datetime_immutable')
            ->setParameter('to', $until, 'utc_datetime_immutable')
            ->orderBy('u.occurredAt', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->setMaxResults(200);
        if ('' !== $requestTail) {
            $query->andWhere('u.requestTail = :tail')->setParameter('tail', $requestTail);
        } elseif ('' !== $sessionId) {
            $query->andWhere('u.sessionId = :session')->setParameter('session', $sessionId);
        } else {
            return null;
        }
        $micros = static fn (\DateTimeImmutable $instant) => $instant->getTimestamp() * 1_000_000 + (int) $instant->format('u');
        $from = $micros($at);
        $to = $micros($until);
        // The turn's own model first (an image generation adds a record of another model at the same instant), then
        // the latest attempt, then the first one queued.
        $best = null;
        foreach ($query->getQuery()->getResult() as $record) {
            $time = $record->occurredAt->getTimestamp() * 1_000_000 + $record->occurredMicros;
            // A record from before the microseconds were kept (0) counts at its second's end: it was stamped after its
            // turn's request, so it belongs to the turn that started before that, not to one starting within the second.
            if (0 === $record->occurredMicros) {
                $time += 999_999;
            }
            $rank = [(int) ('' !== $model && $record->model === $model), $time];
            if ($time >= $from && $time < $to && (null === $best || $rank > $best[0])) {
                $best = [$rank, $record];
            }
        }

        return $best[1] ?? null;
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
     * @return array{requests: int, failed: int, input: int, output: int, cache_read: int, cache_creation: int, cache_5m: int, cache_1h: int, total: int, attempts: int}
     */
    private static function numbers(array $row): array
    {
        $numbers = [];
        foreach (['requests', 'attempts', 'failed', 'input', 'output', 'cache_read', 'cache_creation', 'cache_5m', 'cache_1h', 'total'] as $key) {
            $numbers[$key] = (int) ($row[$key] ?? 0);
        }

        return $numbers;
    }
}

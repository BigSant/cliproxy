<?php

namespace App\Repository;

use App\Entity\Exchange;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Exchange> */
class ExchangeRepository extends ServiceEntityRepository
{
    public const array OUTCOMES = ['failed', 'tool_error', 'thinking', 'prompt'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exchange::class);
    }

    /**
     * The Žurnalas list: newest first, filtered.
     *
     * @param array{provider?: string, model?: string, account?: string, session?: string, outcome?: string, q?: string} $filters
     *
     * @return list<Exchange>
     */
    public function search(array $filters, \DateTimeImmutable $since, int $limit, int $offset = 0): array
    {
        return $this->filtered($filters, $since)
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @param array<string, string> $filters */
    public function countFiltered(array $filters, \DateTimeImmutable $since): int
    {
        return (int) $this->filtered($filters, $since)->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
    }

    /** @return list<Exchange> a session's requests in order */
    public function session(string $sessionId, int $limit = 500): array
    {
        return $this->findBy(['sessionId' => $sessionId], ['occurredAt' => 'ASC', 'id' => 'ASC'], $limit);
    }

    /**
     * Sessions of the period with their size, cost and trouble, newest first.
     *
     * @return list<array<string, int|string>>
     */
    public function sessions(\DateTimeImmutable $since, int $limit): array
    {
        return array_map(static function (array $row) {
            foreach ($row as $key => $value) {
                $row[$key] = \in_array($key, ['session_id', 'started', 'ended', 'account', 'model', 'provider'], true) ? (string) $value : (int) $value;
            }

            return $row;
        }, $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('session_id, MIN(occurred_at) AS started, MAX(occurred_at) AS ended, MAX(account) AS account',
                'MAX(model) AS model, MAX(provider) AS provider, COUNT(*) AS requests',
                "SUM(CASE WHEN prompt_kind = 'user' THEN 1 ELSE 0 END) AS prompts",
                'SUM(tool_call_count) AS tool_calls, SUM(tool_error_count) AS tool_errors',
                'SUM(CASE WHEN failed = 1 THEN 1 ELSE 0 END) AS failed',
                'SUM(input_tokens + output_tokens + cache_read_tokens + cache_creation_tokens) AS tokens',
                'SUM(cache_read_tokens) AS cache_read, SUM(cache_creation_tokens) AS cache_creation')
            ->from('exchange')
            ->where('occurred_at >= :since')
            ->andWhere("session_id <> ''")
            ->setParameter('since', self::utc($since))
            ->groupBy('session_id')
            ->orderBy('ended', 'DESC')
            ->setMaxResults($limit)
            ->fetchAllAssociative());
    }

    /**
     * Totals for the analysis page.
     *
     * @return array<string, int>
     */
    public function totals(\DateTimeImmutable $since): array
    {
        $row = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(*) AS requests, SUM(CASE WHEN failed = 1 THEN 1 ELSE 0 END) AS failed',
                "SUM(CASE WHEN prompt_kind = 'user' THEN 1 ELSE 0 END) AS prompts",
                "SUM(CASE WHEN thinking <> '' THEN 1 ELSE 0 END) AS with_thinking",
                'SUM(tool_call_count) AS tool_calls, SUM(tool_error_count) AS tool_errors',
                'COUNT(DISTINCT session_id) AS sessions, SUM(duration_ms) AS duration_ms, SUM(output_tokens) AS output, SUM(reasoning_tokens) AS reasoning')
            ->from('exchange')
            ->where('occurred_at >= :since')
            ->setParameter('since', self::utc($since))
            ->fetchAssociative() ?: [];

        return array_map('intval', $row);
    }

    /** @return list<array{label: string, count: int}> how answers ended (stop reasons, HTTP errors) */
    public function endings(\DateTimeImmutable $since): array
    {
        $rows = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('failed, status_code, stop_reason, COUNT(*) AS count')
            ->from('exchange')
            ->where('occurred_at >= :since')
            ->setParameter('since', self::utc($since))
            ->groupBy('failed, status_code, stop_reason')
            ->fetchAllAssociative();
        $endings = [];
        foreach ($rows as $row) {
            $label = (int) $row['failed'] ? 'HTTP '.$row['status_code'] : ((string) $row['stop_reason'] ?: '–');
            $endings[$label] = ($endings[$label] ?? 0) + (int) $row['count'];
        }
        arsort($endings);

        return array_map(static fn (string $label, int $count) => ['label' => $label, 'count' => $count], array_keys($endings), $endings);
    }

    /** @return list<Exchange> */
    public function withoutAccount(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('e')
            ->where("e.authIndex = ''")
            ->andWhere("e.requestTail <> ''")
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('since', $since, 'utc_datetime_immutable')
            ->getQuery()
            ->getResult();
    }

    public function forgetArchiveDay(string $day): void
    {
        $this->createQueryBuilder('e')
            ->update()
            ->set('e.archivePath', "''")
            ->where('e.archivePath LIKE :day')
            ->setParameter('day', $day.'/%')
            ->getQuery()
            ->execute();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $before = self::utc($before);
        $connection->executeStatement('DELETE FROM tool_call WHERE exchange_id IN (SELECT id FROM exchange WHERE occurred_at < ?)', [$before]);
        $connection->executeStatement('DELETE FROM exchange WHERE occurred_at < ?', [$before]);
    }

    /** @param array<string, string> $filters */
    private function filtered(array $filters, \DateTimeImmutable $since): QueryBuilder
    {
        $query = $this->createQueryBuilder('e')
            ->where('e.occurredAt >= :since')
            ->setParameter('since', $since, 'utc_datetime_immutable');
        foreach (['provider', 'model', 'sessionId' => 'session', 'account'] as $field => $key) {
            $field = \is_int($field) ? $key : $field;
            if ('' !== ($filters[$key] ?? '')) {
                $query->andWhere("e.$field = :$key")->setParameter($key, $filters[$key]);
            }
        }
        match ($filters['outcome'] ?? '') {
            'failed' => $query->andWhere('e.failed = true'),
            'tool_error' => $query->andWhere('e.toolErrorCount > 0'),
            'thinking' => $query->andWhere("e.thinking <> ''"),
            'prompt' => $query->andWhere("e.promptKind = 'user'"),
            default => null,
        };
        if ('' !== ($filters['q'] ?? '')) {
            $query->andWhere('e.prompt LIKE :q OR e.answer LIKE :q OR e.thinking LIKE :q OR e.error LIKE :q')
                ->setParameter('q', '%'.addcslashes($filters['q'], '%_').'%');
        }

        return $query;
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

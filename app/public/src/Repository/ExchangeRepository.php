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
    public const array KINDS = ['main', 'subagent', 'compaction', 'fork', 'side'];
    /** The list shows the start of each text; the full text is on the exchange's own page. */
    private const int EXCERPT = 400;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Exchange::class);
    }

    /**
     * The Žurnalas list, newest first: the columns it shows and the start of each text, never the long texts whole.
     *
     * @param array<string, string> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function search(array $filters, \DateTimeImmutable $since, int $limit, int $offset = 0): array
    {
        return $this->filtered($filters, $since)
            ->select('e.id, e.occurredAt, e.provider, e.model, e.endpoint, e.account, e.kind, e.promptKind, e.hasThinking',
                'e.toolCallCount, e.toolErrorCount, e.inputTokens, e.outputTokens, e.cacheReadTokens, e.cacheCreationTokens',
                'e.durationMs, e.failed, e.statusCode, e.stopReason',
                'SUBSTRING(e.prompt, 1, '.self::EXCERPT.') AS prompt, SUBSTRING(e.answer, 1, '.self::EXCERPT.') AS answer',
                'SUBSTRING(e.error, 1, '.self::EXCERPT.') AS error')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Exports go back by id (keyset), so neither a long period nor new requests arriving meanwhile shift the pages.
     *
     * @param array<string, string> $filters
     *
     * @return list<Exchange> with their tool calls, newest first
     */
    public function exportBatch(array $filters, \DateTimeImmutable $since, ?int $beforeId, int $limit): array
    {
        $query = $this->filtered($filters, $since)->select('e.id')->orderBy('e.id', 'DESC')->setMaxResults($limit);
        if (null !== $beforeId) {
            $query->andWhere('e.id < :before')->setParameter('before', $beforeId);
        }
        $ids = array_column($query->getQuery()->getArrayResult(), 'id');
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->addSelect('c')
            ->leftJoin('e.toolCalls', 'c')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('e.id', 'DESC')
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
                "SUM(CASE WHEN prompt_kind = 'user' AND kind = 'main' THEN 1 ELSE 0 END) AS prompts",
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
                "SUM(CASE WHEN prompt_kind = 'user' AND kind = 'main' THEN 1 ELSE 0 END) AS prompts",
                'SUM(CASE WHEN has_thinking = 1 THEN 1 ELSE 0 END) AS with_thinking',
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
            ->andWhere("e.requestTail <> '' OR e.sessionId <> ''")
            ->andWhere('e.usageRecordId IS NULL')
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('since', $since, 'utc_datetime_immutable')
            // Oldest first: a WebSocket connection's turns take its usage records in order.
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
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
            'thinking' => $query->andWhere('e.hasThinking = true'),
            'prompt' => $query->andWhere("e.promptKind = 'user'")->andWhere("e.kind = 'main'"),
            default => null,
        };
        if (\in_array($filters['kind'] ?? '', self::KINDS, true)) {
            $query->andWhere('e.kind = :kind')->setParameter('kind', $filters['kind']);
        }
        if ('' !== ($filters['q'] ?? '')) {
            $this->searchText($query, $filters['q']);
        }

        return $query;
    }

    /** InnoDB's default stopwords: never indexed, so a required one would match nothing. */
    private const array STOPWORDS = ['a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i',
        'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who', 'will',
        'with', 'und', 'www'];

    /**
     * Text search over prompt, answer, thinking and error; every word must appear. On MySQL the words full-text can
     * find (3+ characters, not stopwords) go through the index as prefixes (MATCH_AGAINST, in the same query as the
     * other filters, so nothing is cut off); the rest, and everything elsewhere (the SQLite tests), as substrings.
     */
    private function searchText(QueryBuilder $query, string $text): void
    {
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($text)) ?: [], static fn (string $word) => '' !== $word));
        if ([] === $words) {
            return;
        }
        $mysql = $this->getEntityManager()->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
        $indexed = $mysql ? array_values(array_filter($words, static fn (string $word) => mb_strlen($word) >= 3 && !\in_array($word, self::STOPWORDS, true))) : [];
        if ([] !== $indexed) {
            $query->andWhere('MATCH_AGAINST(e.prompt, e.answer, e.thinking, e.error, :fulltext) > 0')
                ->setParameter('fulltext', implode(' ', array_map(static fn (string $word) => '+'.$word.'*', $indexed)));
        }
        foreach (array_values(array_diff($words, $indexed)) as $index => $word) {
            $query->andWhere("e.prompt LIKE :like$index OR e.answer LIKE :like$index OR e.thinking LIKE :like$index OR e.error LIKE :like$index")
                ->setParameter("like$index", '%'.addcslashes($word, '%_\\').'%');
        }
    }

    private static function utc(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

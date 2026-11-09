<?php

namespace App\Entity;

use App\Repository\ExchangeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One request through the hub as its log told it: what came in (a person's prompt or tool results), what the model
 * thought and answered, which tools it called, how it ended. The raw log is kept compressed in the archive for a while.
 */
#[ORM\Entity(repositoryClass: ExchangeRepository::class)]
#[ORM\Index(name: 'exchange_occurred', columns: ['occurred_at'])]
#[ORM\Index(name: 'exchange_session', columns: ['session_id', 'occurred_at'])]
#[ORM\Index(name: 'exchange_request', columns: ['request_tail'])]
#[ORM\Index(name: 'exchange_usage', columns: ['usage_record_id'])]
#[ORM\Index(name: 'exchange_fulltext', columns: ['prompt', 'answer', 'thinking', 'error'], flags: ['fulltext'])]
class Exchange
{
    /** An older parse of a log that a newer one replaced (a WebSocket connection read as one exchange): kept, not counted. */
    public const string KIND_SUPERSEDED = 'superseded';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: 'utc_datetime_immutable')]
    public \DateTimeImmutable $occurredAt;
    /** The microseconds of the start (the column keeps seconds): a WebSocket turn's usage record is told by them. */
    #[ORM\Column(options: ['default' => 0])]
    public int $occurredMicros = 0;
    /** The last eight characters of CLIProxyAPI's request id: the log file's name ends with them, as usage records do. */
    #[ORM\Column(length: 8)]
    public string $requestTail = '';
    #[ORM\Column(length: 190, unique: true)]
    public string $logFile = '';
    #[ORM\Column(length: 64)]
    public string $endpoint = '';
    #[ORM\Column(length: 32)]
    public string $provider = '';
    #[ORM\Column(length: 128)]
    public string $model = '';
    #[ORM\Column(length: 64)]
    public string $client = '';
    #[ORM\Column(length: 64)]
    public string $sessionId = '';
    #[ORM\Column(length: 64)]
    public string $authIndex = '';
    #[ORM\Column(length: 190)]
    public string $account = '';
    /** The usage record this exchange was linked to: one record serves one exchange. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    public ?string $usageRecordId = null;
    #[ORM\Column]
    public bool $stream = false;
    #[ORM\Column]
    public int $statusCode = 0;
    #[ORM\Column]
    public bool $failed = false;
    #[ORM\Column(type: Types::TEXT)]
    public string $error = '';
    #[ORM\Column(length: 64)]
    public string $stopReason = '';
    #[ORM\Column]
    public int $durationMs = 0;
    #[ORM\Column]
    public int $inputTokens = 0;
    #[ORM\Column]
    public int $outputTokens = 0;
    #[ORM\Column]
    public int $cacheReadTokens = 0;
    #[ORM\Column]
    public int $cacheCreationTokens = 0;
    #[ORM\Column]
    public int $reasoningTokens = 0;
    /** main, subagent, compaction, fork, side (Turn::KIND_*): only main turns are a person's work. */
    #[ORM\Column(length: 16, options: ['default' => 'main'])]
    public string $kind = 'main';
    /** Whether $thinking is set: lists and counts read this, not the long text. */
    #[ORM\Column(options: ['default' => false])]
    public bool $hasThinking = false;
    /** user (a person wrote), tool_results (the loop went on), none */
    #[ORM\Column(length: 16)]
    public string $promptKind = 'none';
    #[ORM\Column(type: Types::TEXT)]
    public string $prompt = '';
    #[ORM\Column(type: Types::TEXT)]
    public string $thinking = '';
    #[ORM\Column(type: Types::TEXT)]
    public string $answer = '';
    #[ORM\Column]
    public int $messageCount = 0;
    #[ORM\Column]
    public int $systemChars = 0;
    #[ORM\Column]
    public int $toolsDeclared = 0;
    #[ORM\Column]
    public int $toolCallCount = 0;
    /** Tool results in this request that report a failure. */
    #[ORM\Column]
    public int $toolErrorCount = 0;
    #[ORM\Column]
    public int $requestBytes = 0;
    #[ORM\Column]
    public int $responseBytes = 0;
    /** Path of the compressed raw log, relative to the archive (kept for good). */
    #[ORM\Column(length: 255)]
    public string $archivePath = '';

    /** @var Collection<int, ToolCall> */
    #[ORM\OneToMany(targetEntity: ToolCall::class, mappedBy: 'exchange', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $toolCalls;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->toolCalls = new ArrayCollection();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    /** @return Collection<int, ToolCall> */
    public function getToolCalls(): Collection
    {
        return $this->toolCalls;
    }

    /** @param list<ToolCall> $calls the exchange's calls now; those not among them are removed */
    public function replaceToolCalls(array $calls): void
    {
        foreach ($this->toolCalls->toArray() as $existing) {
            if (!\in_array($existing, $calls, true)) {
                $this->toolCalls->removeElement($existing);
            }
        }
        foreach ($calls as $call) {
            if (!$this->toolCalls->contains($call)) {
                $this->toolCalls->add($call);
            }
        }
        $this->toolCallCount = $this->toolCalls->count();
    }

    public function addToolCall(ToolCall $call): void
    {
        $this->toolCalls->add($call);
        $this->toolCallCount = $this->toolCalls->count();
    }
}

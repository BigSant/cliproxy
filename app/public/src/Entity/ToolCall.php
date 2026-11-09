<?php

namespace App\Entity;

use App\Repository\ToolCallRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tool the model called in an answer. Its result arrives in the client's next request: then whether it worked is
 * known (Claude's is_error, Codex's exit code); null while no result came or the result does not say.
 */
#[ORM\Entity(repositoryClass: ToolCallRepository::class)]
#[ORM\Index(name: 'tool_call_id', columns: ['call_id'])]
#[ORM\Index(name: 'tool_name', columns: ['name'])]
class ToolCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(nullable: true)]
    public ?bool $isError = null;
    #[ORM\Column]
    public bool $answered = false;
    #[ORM\Column(type: Types::TEXT)]
    public string $output = '';
    #[ORM\Column(type: 'utc_datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $answeredAt = null;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'toolCalls')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public readonly Exchange $exchange,
        #[ORM\Column(length: 128)]
        public readonly string $callId,
        #[ORM\Column(length: 128)]
        public readonly string $name,
        #[ORM\Column(type: Types::TEXT)]
        public readonly string $input,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function answer(?bool $isError, string $output, \DateTimeImmutable $at): void
    {
        $this->answered = true;
        $this->isError = $isError;
        $this->output = $output;
        $this->answeredAt = $at;
    }
}

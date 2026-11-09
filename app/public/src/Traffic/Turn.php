<?php

namespace App\Traffic;

/**
 * What one proxied request did, read from its log: the new input (a person's prompt or tool results), the model's
 * thinking, answer and tool calls, how it ended and the tokens. Built by AnthropicFormat or ResponsesFormat.
 */
final class Turn
{
    public const string PROMPT_USER = 'user';
    public const string PROMPT_TOOL_RESULTS = 'tool_results';
    public const string PROMPT_NONE = 'none';

    public string $model = '';
    public bool $stream = false;
    public string $prompt = '';
    public string $promptKind = self::PROMPT_NONE;
    /** @var list<array{callId: string, isError: ?bool, output: string}> results of earlier tool calls sent in this request */
    public array $toolResults = [];
    public string $thinking = '';
    public string $text = '';
    /** @var list<array{callId: string, name: string, input: string}> */
    public array $toolCalls = [];
    public string $stopReason = '';
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $cacheReadTokens = 0;
    public int $cacheCreationTokens = 0;
    public int $reasoningTokens = 0;
    public string $error = '';
    public string $sessionId = '';
    public int $messageCount = 0;
    public int $systemChars = 0;
    public int $toolsDeclared = 0;

    /**
     * Server-sent events: the JSON of every "data:" line, with its "event:" name when the line before gave one.
     *
     * @return list<array<string, mixed>>
     */
    public static function events(string $body): array
    {
        $events = [];
        $name = null;
        foreach (explode("\n", $body) as $line) {
            if (str_starts_with($line, 'event:')) {
                $name = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data = json_decode(trim(substr($line, 5)), true);
                if (\is_array($data)) {
                    $events[] = $data + (null !== $name && !isset($data['type']) ? ['type' => $name] : []);
                }
                $name = null;
            }
        }

        return $events;
    }

    public static function isEventStream(string $body): bool
    {
        return 1 === preg_match('/^(event|data):/m', substr($body, 0, 2000));
    }

    /** Text of a content value that is a string or a list of blocks with "text". */
    public static function textOf(mixed $content): string
    {
        if (\is_string($content)) {
            return $content;
        }
        if (!\is_array($content)) {
            return '';
        }
        $texts = [];
        foreach ($content as $block) {
            if (\is_array($block) && isset($block['text']) && \is_string($block['text'])) {
                $texts[] = $block['text'];
            } elseif (\is_array($block) && \in_array($block['type'] ?? null, ['image', 'input_image'], true)) {
                $texts[] = '[paveikslėlis]';
            }
        }

        return implode("\n", $texts);
    }
}

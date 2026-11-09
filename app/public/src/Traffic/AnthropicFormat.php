<?php

namespace App\Traffic;

/** /v1/messages (Claude Code): the Anthropic Messages request and its answer, streamed or whole. */
final class AnthropicFormat
{
    /** @param array<string, mixed> $request */
    public static function read(array $request, string $response, Turn $turn): void
    {
        $turn->model = (string) ($request['model'] ?? '');
        $turn->stream = (bool) ($request['stream'] ?? false);
        $messages = \is_array($request['messages'] ?? null) ? $request['messages'] : [];
        $turn->messageCount = \count($messages);
        $turn->systemChars = mb_strlen(Turn::textOf($request['system'] ?? ''));
        $turn->toolsDeclared = \is_array($request['tools'] ?? null) ? \count($request['tools']) : 0;
        $turn->sessionId = self::sessionId($request['metadata']['user_id'] ?? null);
        if (isset($request['max_tokens']) && (int) $request['max_tokens'] <= 1) {
            $turn->kind = Turn::KIND_SIDE;
        }

        $last = end($messages);
        if (\is_array($last) && 'user' === ($last['role'] ?? null)) {
            self::readInput($last['content'] ?? '', $turn);
        }

        $answer = Turn::isEventStream($response) ? self::fromEvents(Turn::events($response)) : json_decode($response, true);
        if (!\is_array($answer)) {
            return;
        }
        if ('error' === ($answer['type'] ?? null) || isset($answer['error'])) {
            $turn->error = (string) ($answer['error']['message'] ?? json_encode($answer['error'] ?? $answer));
        }
        $turn->model = (string) ($answer['model'] ?? $turn->model);
        $turn->stopReason = (string) ($answer['stop_reason'] ?? '');
        $usage = \is_array($answer['usage'] ?? null) ? $answer['usage'] : [];
        $turn->inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $turn->outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $turn->cacheReadTokens = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $turn->cacheCreationTokens = (int) ($usage['cache_creation_input_tokens'] ?? 0);
        $thinking = $text = [];
        foreach (\is_array($answer['content'] ?? null) ? $answer['content'] : [] as $block) {
            match ($block['type'] ?? null) {
                'thinking' => $thinking[] = (string) ($block['thinking'] ?? ''),
                'redacted_thinking' => $thinking[] = '[užšifruotas mąstymas]',
                'text' => $text[] = (string) ($block['text'] ?? ''),
                'tool_use', 'server_tool_use' => $turn->toolCalls[] = [
                    'callId' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => \is_string($block['input'] ?? null) ? $block['input'] : json_encode($block['input'] ?? new \stdClass(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                ],
                default => null,
            };
        }
        $turn->thinking = implode("\n\n", array_filter($thinking, 'strlen'));
        $turn->text = implode("\n\n", array_filter($text, 'strlen'));
    }

    /** The last user message: a person's prompt, or the results of the tools the model called before. */
    private static function readInput(mixed $content, Turn $turn): void
    {
        if (\is_string($content)) {
            $turn->prompt = Turn::cleanPrompt($content);
            $turn->promptKind = '' !== $turn->prompt ? Turn::PROMPT_USER : Turn::PROMPT_NONE;

            return;
        }
        $texts = [];
        foreach (\is_array($content) ? $content : [] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            if ('tool_result' === ($block['type'] ?? null)) {
                $turn->toolResults[] = [
                    'callId' => (string) ($block['tool_use_id'] ?? ''),
                    'isError' => (bool) ($block['is_error'] ?? false),
                    'output' => Turn::textOf($block['content'] ?? ''),
                ];
            } elseif ('text' === ($block['type'] ?? null) || 'image' === ($block['type'] ?? null)) {
                $texts[] = Turn::textOf([$block]);
            }
        }
        $turn->prompt = Turn::cleanPrompt(implode("\n", $texts));
        $turn->promptKind = [] !== $turn->toolResults ? Turn::PROMPT_TOOL_RESULTS : ('' !== $turn->prompt ? Turn::PROMPT_USER : Turn::PROMPT_NONE);
    }

    /**
     * The whole message rebuilt from its stream: message_start, content_block_start/delta/stop, message_delta.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function fromEvents(array $events): array
    {
        $message = ['content' => [], 'usage' => []];
        $json = [];
        foreach ($events as $event) {
            switch ($event['type'] ?? null) {
                case 'message_start':
                    $start = \is_array($event['message'] ?? null) ? $event['message'] : [];
                    $message = ['content' => [], 'usage' => (array) ($start['usage'] ?? [])] + $start;
                    break;
                case 'content_block_start':
                    $message['content'][(int) ($event['index'] ?? 0)] = (array) ($event['content_block'] ?? []);
                    break;
                case 'content_block_delta':
                    $index = (int) ($event['index'] ?? 0);
                    $delta = (array) ($event['delta'] ?? []);
                    $block = &$message['content'][$index];
                    match ($delta['type'] ?? null) {
                        'thinking_delta' => $block['thinking'] = ($block['thinking'] ?? '').($delta['thinking'] ?? ''),
                        'text_delta' => $block['text'] = ($block['text'] ?? '').($delta['text'] ?? ''),
                        'input_json_delta' => $json[$index] = ($json[$index] ?? '').($delta['partial_json'] ?? ''),
                        default => null,
                    };
                    unset($block);
                    break;
                case 'message_delta':
                    $message['stop_reason'] = $event['delta']['stop_reason'] ?? null;
                    $message['usage'] = array_merge($message['usage'], array_filter((array) ($event['usage'] ?? []), static fn ($v) => null !== $v));
                    break;
                case 'error':
                    $message['error'] = $event['error'] ?? $event;
                    break;
            }
        }
        foreach ($json as $index => $input) {
            $message['content'][$index]['input'] = '' !== $input ? $input : '{}';
        }
        ksort($message['content']);
        $message['content'] = array_values($message['content']);

        return $message;
    }

    /** Claude Code's metadata.user_id: "user_…_account_…_session_<uuid>", or JSON with session_id. */
    private static function sessionId(mixed $userId): string
    {
        if (!\is_string($userId)) {
            return '';
        }
        $decoded = json_decode($userId, true);
        if (\is_array($decoded) && \is_string($decoded['session_id'] ?? null)) {
            return $decoded['session_id'];
        }

        return preg_match('/session_([0-9a-f-]{36})/i', $userId, $match) ? $match[1] : '';
    }
}

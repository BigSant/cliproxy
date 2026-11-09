<?php

namespace App\Traffic;

/** /v1/responses (Codex): the OpenAI Responses request and its answer, streamed or whole. */
final class ResponsesFormat
{
    /** @param array<string, mixed> $request */
    public static function read(array $request, string $response, Turn $turn): void
    {
        self::readRequest($request, $turn);
        $answer = Turn::isEventStream($response) ? self::fromEvents(Turn::events($response)) : json_decode($response, true);
        if (\is_array($answer)) {
            self::readAnswer($answer, $turn);
        }
    }

    /**
     * One turn of a Codex WebSocket connection: its response.create and the answer's events.
     *
     * @param array<string, mixed>       $request
     * @param list<array<string, mixed>> $events
     */
    public static function readEvents(array $request, array $events, Turn $turn): void
    {
        self::readRequest($request, $turn);
        $turn->stream = true;
        self::readAnswer(self::fromEvents($events), $turn);
    }

    /** @param array<string, mixed> $request */
    private static function readRequest(array $request, Turn $turn): void
    {
        $turn->model = (string) ($request['model'] ?? '');
        $turn->effort = (string) ($request['reasoning']['effort'] ?? '');
        $turn->stream = (bool) ($request['stream'] ?? false);
        $turn->systemChars = mb_strlen((string) ($request['instructions'] ?? ''));
        $turn->toolsDeclared = \is_array($request['tools'] ?? null) ? \count($request['tools']) : 0;
        // CLIProxyAPI names a session from prompt_cache_key "pck:<key>" (sdk/cliproxy/session/info.go); a client's
        // Session_id header, when there is one, wins over it.
        $turn->sessionId = isset($request['prompt_cache_key']) && '' !== $request['prompt_cache_key'] ? 'pck:'.$request['prompt_cache_key'] : '';
        $input = $request['input'] ?? [];
        if (\is_string($input)) {
            $input = [['type' => 'message', 'role' => 'user', 'content' => $input]];
        }
        $input = \is_array($input) ? array_values($input) : [];
        $turn->messageCount = \count($input);
        self::readInput($input, $turn);
    }

    /** @param array<string, mixed> $answer */
    private static function readAnswer(array $answer, Turn $turn): void
    {
        $answer = \is_array($answer['response'] ?? null) ? $answer['response'] : $answer;
        if (\is_array($answer['error'] ?? null)) {
            $turn->error = (string) ($answer['error']['message'] ?? json_encode($answer['error']));
        }
        $turn->model = (string) ($answer['model'] ?? $turn->model);
        $turn->stopReason = (string) ($answer['status'] ?? '');
        if ('incomplete' === $turn->stopReason && isset($answer['incomplete_details']['reason'])) {
            $turn->stopReason .= ': '.$answer['incomplete_details']['reason'];
        }
        $usage = \is_array($answer['usage'] ?? null) ? $answer['usage'] : [];
        $cached = (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0);
        $turn->inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0) - $cached);
        $turn->cacheReadTokens = $cached;
        $turn->outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $turn->reasoningTokens = (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0);
        $thinking = $text = [];
        foreach (\is_array($answer['output'] ?? null) ? $answer['output'] : [] as $item) {
            switch ($item['type'] ?? null) {
                case 'reasoning':
                    $thinking[] = Turn::textOf($item['summary'] ?? []) ?: Turn::textOf($item['content'] ?? []);
                    break;
                case 'message':
                    $text[] = Turn::textOf($item['content'] ?? []);
                    break;
                case 'function_call':
                case 'custom_tool_call':
                case 'local_shell_call':
                case 'web_search_call':
                    $turn->toolCalls[] = [
                        'callId' => (string) ($item['call_id'] ?? $item['id'] ?? ''),
                        'name' => (string) ($item['name'] ?? $item['type']),
                        'input' => (string) ($item['arguments'] ?? $item['input'] ?? json_encode($item['action'] ?? new \stdClass(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)),
                    ];
                    break;
            }
        }
        $turn->thinking = implode("\n\n", array_filter($thinking, 'strlen'));
        $turn->text = implode("\n\n", array_filter($text, 'strlen'));
    }

    /**
     * What is new in this request: everything after the model's last output item. Tool outputs carry the result of
     * a call; Codex reports a command's exit code in it, which tells success from failure.
     *
     * @param list<mixed> $input
     */
    private static function readInput(array $input, Turn $turn): void
    {
        $start = 0;
        foreach ($input as $index => $item) {
            if (\is_array($item) && (\in_array($item['type'] ?? null, ['function_call', 'custom_tool_call', 'reasoning', 'local_shell_call'], true)
                || ('message' === ($item['type'] ?? 'message') && 'assistant' === ($item['role'] ?? null)))) {
                $start = $index + 1;
            }
        }
        $texts = [];
        foreach (\array_slice($input, $start) as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? 'message';
            if (\in_array($type, ['function_call_output', 'custom_tool_call_output', 'local_shell_call_output'], true)) {
                $output = $item['output'] ?? '';
                $output = \is_string($output) ? $output : Turn::textOf($output);
                $turn->toolResults[] = ['callId' => (string) ($item['call_id'] ?? ''), 'isError' => self::failed($output), 'output' => $output];
            } elseif ('message' === $type && 'user' === ($item['role'] ?? null)) {
                $texts[] = Turn::textOf($item['content'] ?? '');
            }
        }
        $turn->prompt = Turn::cleanPrompt(implode("\n", array_filter($texts, 'strlen')));
        $turn->promptKind = [] !== $turn->toolResults ? Turn::PROMPT_TOOL_RESULTS : Turn::promptKindOf($turn->prompt);
    }

    /** true/false from an exit code in the output ({"metadata":{"exit_code":1}}, "Exit code: 1"); null when none. */
    public static function failed(string $output): ?bool
    {
        $decoded = json_decode($output, true);
        if (\is_array($decoded) && isset($decoded['metadata']['exit_code']) && is_numeric($decoded['metadata']['exit_code'])) {
            return 0 !== (int) $decoded['metadata']['exit_code'];
        }
        if (preg_match('/(?:exit code|exit_code|exited with code)["\s:=]*(-?\d+)/i', substr($output, 0, 4000), $match)) {
            return 0 !== (int) $match[1];
        }

        return null;
    }

    /**
     * The final response from a stream: response.completed (or failed/incomplete) holds it whole; output items seen
     * in response.output_item.done fill it in when that event lacks them.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function fromEvents(array $events): array
    {
        $response = [];
        $items = [];
        foreach ($events as $event) {
            $type = $event['type'] ?? null;
            if ('response.output_item.done' === $type && \is_array($event['item'] ?? null)) {
                $items[] = $event['item'];
            } elseif (\in_array($type, ['response.completed', 'response.done', 'response.failed', 'response.incomplete', 'response.created'], true) && \is_array($event['response'] ?? null)) {
                $response = $event['response'];
            } elseif ('error' === $type) {
                $response['error'] = $event['error'] ?? ['message' => $event['message'] ?? 'error'];
                $response['status'] ??= 'failed';
            }
        }
        if ([] === ($response['output'] ?? []) && [] !== $items) {
            $response['output'] = $items;
        }

        return $response;
    }
}

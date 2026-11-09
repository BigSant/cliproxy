<?php

namespace App\Tests\Support;

/** Request log files as CLIProxyAPI writes them (internal/logging/request_logger_format.go), for the traffic tests. */
final class RequestLogs
{
    /** @param array<string, mixed> $request */
    public static function file(string $url, array $request, string $response, int $status = 200, string $userAgent = 'claude-cli/2.1.280 (external, cli)', array $headers = [], string $at = '2026-11-09T15:00:00.000000000+02:00'): string
    {
        $headerLines = '';
        foreach (['User-Agent' => $userAgent, 'Authorization' => 'Bearer 6647...bbfe', 'Content-Type' => 'application/json'] + $headers as $name => $value) {
            $headerLines .= "$name: $value\n";
        }

        return "=== REQUEST INFO ===\nVersion: v8.0.61\nURL: $url\nMethod: POST\nDownstream Transport: http\nUpstream Transport: http\nTimestamp: $at\n\n\n"
            ."=== HEADERS ===\n$headerLines\n\n"
            .'=== REQUEST BODY ==='."\n".json_encode($request, \JSON_UNESCAPED_SLASHES)."\n\n\n"
            ."=== API REQUEST ===\nTimestamp: $at\n{\"upstream\":true}\n\n\n"
            ."=== API RESPONSE ===\nTimestamp: $at\n(upstream bytes)\n\n\n"
            ."=== RESPONSE ===\nStatus: $status\nContent-Type: text/event-stream\n\n".$response."\n";
    }

    /** Claude Code's first turn: a prompt, the model thinks and calls Bash. */
    public static function claudeToolUse(): string
    {
        $events = [
            ['message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-fable-5-1', 'usage' => ['input_tokens' => 12, 'cache_read_input_tokens' => 9000, 'cache_creation_input_tokens' => 300]]]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'Reikia paleisti ']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => 'testus.']]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'text_delta', 'text' => 'Paleidžiu testus.']]],
            ['content_block_start', ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => new \stdClass()]]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"command":"bin/']]],
            ['content_block_delta', ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'phpunit"}']]],
            ['message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 85]]],
            ['message_stop', ['type' => 'message_stop']],
        ];
        $sse = '';
        foreach ($events as [$name, $data]) {
            $sse .= "event: $name\ndata: ".json_encode($data, \JSON_UNESCAPED_UNICODE)."\n\n";
        }

        return self::file('/v1/messages?beta=true', [
            'model' => 'claude-fable-5-1', 'stream' => true, 'max_tokens' => 32000,
            'metadata' => ['user_id' => 'user_abc_account_def_session_11111111-2222-3333-4444-555555555555'],
            'system' => [['type' => 'text', 'text' => 'You are Claude Code.']],
            'tools' => [['name' => 'Bash'], ['name' => 'Read']],
            'messages' => [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Paleisk testus']]]],
        ], $sse);
    }

    /** The next turn: the Bash result says it failed; the model answers. */
    public static function claudeToolResult(bool $isError = true): string
    {
        return self::file('/v1/messages?beta=true', [
            'model' => 'claude-fable-5-1', 'stream' => false,
            'metadata' => ['user_id' => 'user_abc_account_def_session_11111111-2222-3333-4444-555555555555'],
            'messages' => [
                ['role' => 'user', 'content' => 'Paleisk testus'],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'Bash', 'input' => ['command' => 'bin/phpunit']]]],
                ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'is_error' => $isError, 'content' => 'FAILURES! Tests: 3, Failures: 1']]],
            ],
        ], json_encode(['type' => 'message', 'model' => 'claude-fable-5-1', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Vienas testas krenta.']], 'usage' => ['input_tokens' => 40, 'output_tokens' => 9]]), at: '2026-11-09T15:00:20.000000000+02:00');
    }

    /** Codex: reasoning, then a shell call. */
    public static function codexCall(): string
    {
        $completed = ['type' => 'response.completed', 'response' => [
            'model' => 'gpt-5.5-codex', 'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'Patikrinsiu failus.']]],
                ['type' => 'function_call', 'name' => 'exec_command', 'call_id' => 'call_9', 'arguments' => '{"cmd":["ls"]}'],
            ],
            'usage' => ['input_tokens' => 5000, 'input_tokens_details' => ['cached_tokens' => 4000], 'output_tokens' => 60, 'output_tokens_details' => ['reasoning_tokens' => 40]],
        ]];
        $sse = "event: response.created\ndata: {\"type\":\"response.created\",\"response\":{\"status\":\"in_progress\"}}\n\n"
            .'event: response.completed'."\ndata: ".json_encode($completed, \JSON_UNESCAPED_UNICODE)."\n\n";

        return self::file('/v1/responses', [
            'model' => 'gpt-5.5-codex', 'stream' => true, 'instructions' => 'You are Codex.', 'prompt_cache_key' => 'codex-session-1',
            'input' => [['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Kas kataloge?']]]],
        ], $sse, userAgent: 'codex_cli_rs/0.149.1', headers: ['Session_id' => 'codex-session-1']);
    }

    public static function codexResult(int $exitCode): string
    {
        return self::file('/v1/responses', [
            'model' => 'gpt-5.5-codex', 'stream' => false, 'prompt_cache_key' => 'codex-session-1',
            'input' => [
                ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Kas kataloge?']]],
                ['type' => 'function_call', 'name' => 'exec_command', 'call_id' => 'call_9', 'arguments' => '{"cmd":["ls"]}'],
                ['type' => 'function_call_output', 'call_id' => 'call_9', 'output' => json_encode(['output' => 'ls: cannot access', 'metadata' => ['exit_code' => $exitCode]])],
            ],
        ], json_encode(['model' => 'gpt-5.5-codex', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Katalogas tuščias.']]]], 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]]),
            userAgent: 'codex_cli_rs/0.149.1', at: '2026-11-09T15:01:00.000000000+02:00');
    }

    /** A Codex WebSocket connection: two turns, the second sending the first one's tool result (exit code 1). */
    public static function codexWebsocket(): string
    {
        $event = static fn (string $at, string $type, array $payload) => "Timestamp: $at\nEvent: websocket.$type\n".json_encode($payload, \JSON_UNESCAPED_UNICODE)."\n";
        $timeline = implode("\n", [
            $event('2026-11-09T15:00:00.000000000+02:00', 'request', ['type' => 'response.create', 'model' => 'gpt-5.5-codex', 'prompt_cache_key' => 'ws-session',
                'input' => [['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => "<environment_context>cwd</environment_context>\nPaleisk testus"]]]]]),
            $event('2026-11-09T15:00:02.000000000+02:00', 'response', ['type' => 'response.output_item.done', 'item' => ['type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'Paleisiu phpunit.']]]]),
            $event('2026-11-09T15:00:03.000000000+02:00', 'response', ['type' => 'response.output_item.done', 'item' => ['type' => 'function_call', 'name' => 'exec_command', 'call_id' => 'call_ws1', 'arguments' => '{"cmd":["bin/phpunit"]}']]),
            $event('2026-11-09T15:00:03.500000000+02:00', 'response', ['type' => 'response.completed', 'response' => ['model' => 'gpt-5.5-codex', 'status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 900, 'input_tokens_details' => ['cached_tokens' => 800], 'output_tokens' => 30]]]),
            $event('2026-11-09T15:00:40.000000000+02:00', 'request', ['type' => 'response.create', 'model' => 'gpt-5.5-codex', 'prompt_cache_key' => 'ws-session', 'previous_response_id' => 'resp_1',
                'input' => [['type' => 'function_call_output', 'call_id' => 'call_ws1', 'output' => '{"output":"FAILURES!","metadata":{"exit_code":1}}']]]),
            $event('2026-11-09T15:00:45.000000000+02:00', 'response', ['type' => 'response.completed', 'response' => ['model' => 'gpt-5.5-codex', 'status' => 'completed',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Vienas testas krenta.']]]], 'usage' => ['input_tokens' => 50, 'output_tokens' => 8]]]),
            $event('2026-11-09T15:10:00.000000000+02:00', 'disconnect', ['message' => 'client closed']),
        ]);

        return "=== REQUEST INFO ===\nVersion: v8.0.61\nURL: /v1/responses\nMethod: GET\nDownstream Transport: websocket\nUpstream Transport: websocket\nTimestamp: 2026-11-09T15:00:00.000000000+02:00\n\n\n"
            ."=== HEADERS ===\nUser-Agent: codex_cli_rs/0.149.1\nSession_id: ws-session\nUpgrade: websocket\n\n\n"
            ."=== WEBSOCKET TIMELINE ===\n".$timeline."\n\n"
            ."=== API WEBSOCKET TIMELINE ===\nTimestamp: 2026-11-09T15:00:00.000000000+02:00\nEvent: websocket.request\n{}\n";
    }
}

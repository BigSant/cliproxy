<?php

namespace App\Traffic;

/**
 * A Codex WebSocket connection's request log: CLIProxyAPI writes one file per connection, when it closes, with its
 * "=== WEBSOCKET TIMELINE ===" section and no REQUEST BODY or RESPONSE (sdk/api/handlers/openai/
 * openai_responses_websocket_timeline.go). The section is a list of events:
 *
 *     Timestamp: 2026-11-09T15:00:00.123456789+02:00
 *     Event: websocket.request          (the client's response.create: model, input, …)
 *     {…json…}
 *
 *     Timestamp: …
 *     Event: websocket.response         (one event of the answer: response.output_item.done, response.completed, …)
 *     {…json…}
 *
 * and websocket.disconnect with the reason. Each request and the answer events after it make one turn.
 */
final class WebsocketTimeline
{
    /**
     * @return list<array{at: ?\DateTimeImmutable, endedAt: ?\DateTimeImmutable, request: array<string, mixed>, events: list<array<string, mixed>>, disconnect: string, bytes: int}>
     */
    public static function turns(string $section): array
    {
        $parts = preg_split('/^Timestamp: (\S+)\nEvent: websocket\.([a-z_]+)\n/m', $section, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $turns = [];
        $current = null;
        for ($i = 1; $i + 2 < \count($parts); $i += 3) {
            $at = self::instant($parts[$i]);
            $type = $parts[$i + 1];
            $payload = trim($parts[$i + 2]);
            if ('request' === $type) {
                if (null !== $current) {
                    $turns[] = $current;
                }
                $request = json_decode($payload, true);
                $request = \is_array($request) ? $request : [];
                // response.create carries the request flat, or (older clients) under "response".
                if (\is_array($request['response'] ?? null)) {
                    $request = $request['response'] + $request;
                }
                $current = ['at' => $at, 'endedAt' => $at, 'request' => $request, 'events' => [], 'disconnect' => '', 'bytes' => \strlen($payload)];
                continue;
            }
            if (null === $current) {
                continue;
            }
            $current['endedAt'] = $at ?? $current['endedAt'];
            if ('response' === $type) {
                foreach (self::events($payload) as $event) {
                    $current['events'][] = $event;
                }
                $current['bytes'] += \strlen($payload);
            } elseif ('disconnect' === $type) {
                $current['disconnect'] = $payload;
            }
        }
        if (null !== $current) {
            $turns[] = $current;
        }

        return $turns;
    }

    /** @return list<array<string, mixed>> one JSON event, or several on lines of their own */
    private static function events(string $payload): array
    {
        $event = json_decode($payload, true);
        if (\is_array($event)) {
            return [$event];
        }
        $events = [];
        foreach (explode("\n", $payload) as $line) {
            $event = json_decode(trim(preg_replace('/^data:\s*/', '', $line) ?? ''), true);
            if (\is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private static function instant(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

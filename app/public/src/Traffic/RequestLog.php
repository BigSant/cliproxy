<?php

namespace App\Traffic;

/**
 * One request log file of CLIProxyAPI (observability.logs.request-log): sections headed "=== NAME ===", in order
 * REQUEST INFO, HEADERS, REQUEST BODY, then the upstream API REQUEST / API RESPONSE (one per attempt) and API ERROR
 * RESPONSE, and last RESPONSE, what the client got (status, headers, blank line, body to the end of the file).
 */
final readonly class RequestLog
{
    /**
     * @param array<string, string>       $info      REQUEST INFO lines (URL, Method, Timestamp, ...)
     * @param array<string, string>       $headers   lower-case names; CLIProxyAPI masks secrets itself
     * @param array<string, list<string>> $sections  every other section's text, in file order
     * @param array<string, string>       $responseHeaders lower-case names
     */
    public function __construct(
        public array $info,
        public array $headers,
        public string $requestBody,
        public array $sections,
        public ?int $status,
        public array $responseHeaders,
        public string $responseBody,
    ) {
    }

    public static function parse(string $text): self
    {
        $text = str_replace("\r\n", "\n", $text);
        // RESPONSE is last and its body runs to the end: split only before it.
        $responseAt = strrpos($text, "\n=== RESPONSE ===\n");
        if (false === $responseAt && str_starts_with($text, "=== RESPONSE ===\n")) {
            $responseAt = -1;
        }
        $head = false === $responseAt ? $text : substr($text, 0, max(0, $responseAt));
        $response = false === $responseAt ? '' : substr($text, $responseAt + \strlen("\n=== RESPONSE ===\n"));

        $sections = [];
        // CLIProxyAPI v8 numbers the attempts' sections (API REQUEST 1, API RESPONSE 2, …): they gather under one name,
        // in order.
        $parts = preg_split('/^=== ([A-Z][A-Z ]*[A-Z])(?: \d+)? ===$\n?/m', $head, -1, \PREG_SPLIT_DELIM_CAPTURE) ?: [];
        for ($i = 1; $i + 1 < \count($parts); $i += 2) {
            $sections[$parts[$i]][] = rtrim($parts[$i + 1], "\n");
        }
        $info = self::lines($sections['REQUEST INFO'][0] ?? '');
        $headers = array_change_key_case(self::lines($sections['HEADERS'][0] ?? ''));
        $requestBody = $sections['REQUEST BODY'][0] ?? '';
        unset($sections['REQUEST INFO'], $sections['HEADERS'], $sections['REQUEST BODY']);

        [$responseHead, $responseBody] = str_contains($response, "\n\n") ? explode("\n\n", $response, 2) : [$response, ''];
        $responseHeaders = array_change_key_case(self::lines($responseHead));
        $status = isset($responseHeaders['status']) && ctype_digit($responseHeaders['status']) ? (int) $responseHeaders['status'] : null;
        unset($responseHeaders['status']);

        return new self($info, $headers, $requestBody, $sections, $status, $responseHeaders, rtrim($responseBody, "\n"));
    }

    public function url(): string
    {
        return $this->info['URL'] ?? '';
    }

    /** The endpoint without its query: /v1/messages, /v1/responses, ... */
    public function path(): string
    {
        return strtok($this->url(), '?') ?: '';
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return self::instant($this->info['Timestamp'] ?? null);
    }

    /** When the last upstream answer began (its section's Timestamp line). */
    public function upstreamAnsweredAt(): ?\DateTimeImmutable
    {
        $answers = $this->sections['API RESPONSE'] ?? [];
        $last = end($answers);
        if (false === $last || !preg_match('/^Timestamp: (\S+)/', $last, $match)) {
            return null;
        }

        return self::instant($match[1]);
    }

    /** The upstream error answers CLIProxyAPI recorded (failed attempts, final error). */
    public function apiErrors(): string
    {
        return implode("\n", $this->sections['API ERROR RESPONSE'] ?? []);
    }

    /** @return array<string, string> "Name: value" lines; the first of a repeated name wins */
    private static function lines(string $block): array
    {
        $lines = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([A-Za-z0-9_-]+(?: [A-Za-z]+)*): ?(.*)$/', $line, $match) && !isset($lines[$match[1]])) {
                $lines[$match[1]] = $match[2];
            }
        }

        return $lines;
    }

    private static function instant(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Server-Sent Events (SSE) subscription client.
 *
 * Connects to an OJS backend's SSE endpoint and streams lifecycle events
 * to a user-provided callback.
 */
class SSESubscription
{
    private bool $active = false;
    /** @var resource|null */
    private $curlHandle = null;

    private function __construct(
        private readonly string $url,
        private readonly array $headers,
    ) {}

    /**
     * Subscribe to events for a specific job.
     */
    public static function forJob(
        string $baseUrl,
        string $jobId,
        callable $callback,
        ?string $authToken = null,
    ): self {
        $url = rtrim($baseUrl, '/') . "/ojs/v1/events/jobs/{$jobId}";
        $sub = new self($url, self::buildHeaders($authToken));
        $sub->connect($callback);
        return $sub;
    }

    /**
     * Subscribe to events for a specific queue.
     */
    public static function forQueue(
        string $baseUrl,
        string $queue,
        callable $callback,
        ?string $authToken = null,
    ): self {
        $url = rtrim($baseUrl, '/') . "/ojs/v1/events/queues/{$queue}";
        $sub = new self($url, self::buildHeaders($authToken));
        $sub->connect($callback);
        return $sub;
    }

    /**
     * Subscribe to all events.
     */
    public static function forAll(
        string $baseUrl,
        callable $callback,
        ?string $authToken = null,
    ): self {
        $url = rtrim($baseUrl, '/') . '/ojs/v1/events';
        $sub = new self($url, self::buildHeaders($authToken));
        $sub->connect($callback);
        return $sub;
    }

    /**
     * Cancel the subscription and close the connection.
     */
    public function cancel(): void
    {
        $this->active = false;
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    private function connect(callable $callback): void
    {
        $this->active = true;
        $buffer = '';

        $ch = curl_init($this->url);
        if ($ch === false) {
            throw new ConnectionError("Failed to connect to SSE endpoint: {$this->url}");
        }
        $this->curlHandle = $ch;

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => array_merge($this->headers, ['Accept: text/event-stream']),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, $callback): int {
                if (!$this->active) {
                    return 0;
                }
                $buffer .= $data;
                $this->parseSSE($buffer, $callback);
                return strlen($data);
            },
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0,
        ]);

        curl_exec($ch);
        $this->active = false;

        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    private function parseSSE(string &$buffer, callable $callback): void
    {
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $block = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $eventType = 'message';
            $eventData = '';
            $eventId = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $eventType = ltrim(substr($line, 6));
                } elseif (str_starts_with($line, 'data:')) {
                    $chunk = ltrim(substr($line, 5));
                    $eventData = $eventData === '' ? $chunk : $eventData . "\n" . $chunk;
                } elseif (str_starts_with($line, 'id:')) {
                    $eventId = ltrim(substr($line, 3));
                }
            }

            if ($eventData === '') {
                continue;
            }

            $decoded = json_decode($eventData, true) ?? [];
            $event = new Event(
                type: $eventType,
                data: $decoded,
                id: $eventId,
                time: $decoded['time'] ?? date('c'),
            );

            $callback($event);
        }
    }

    private static function buildHeaders(?string $authToken): array
    {
        $headers = ['Cache-Control: no-cache'];
        if ($authToken !== null) {
            $headers[] = "Authorization: Bearer {$authToken}";
        }
        return $headers;
    }
}

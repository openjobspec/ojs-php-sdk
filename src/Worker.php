<?php

declare(strict_types=1);

namespace OpenJobSpec;

class Worker
{
    private string $baseUrl;
    private array $handlers = [];
    private int $concurrency;
    private bool $running = false;

    public function __construct(string $url, array $options = [])
    {
        $this->baseUrl = rtrim($url, '/');
        $this->concurrency = $options['concurrency'] ?? 5;
    }

    public function register(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function start(): void
    {
        $this->running = true;
        while ($this->running) {
            $this->poll();
            usleep(1_000_000); // 1 second
        }
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function poll(): void
    {
        // Fetch jobs from server and dispatch to handlers
        $ch = curl_init($this->baseUrl . '/ojs/v1/workers/fetch');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'queues' => array_keys($this->handlers) ?: ['default'],
                'count' => $this->concurrency,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        foreach (($response['jobs'] ?? []) as $jobData) {
            $job = Job::fromArray($jobData);
            $handler = $this->handlers[$job->type] ?? null;
            if ($handler !== null) {
                try {
                    $handler($job);
                } catch (\Throwable $e) {
                    // NACK
                }
            }
        }
    }
}

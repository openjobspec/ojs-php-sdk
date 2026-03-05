<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * OJS Client — enqueue and manage jobs on an OJS-compliant backend.
 */
class Client
{
    private Transport $transport;

    public function __construct(string $url, array $options = [])
    {
        if (isset($options['transport']) && $options['transport'] instanceof Transport) {
            $this->transport = $options['transport'];
        } else {
            $this->transport = new HttpTransport($url, $options);
        }
    }

    // ── Enqueue ─────────────────────────────────────────────

    /**
     * Enqueue a single job.
     */
    public function enqueue(string $type, array $args = [], array $options = []): Job
    {
        $body = self::buildJobPayload($type, $args, $options);
        $response = $this->transport->post('/ojs/v1/jobs', $body);
        return Job::fromArray($response);
    }

    /**
     * Enqueue multiple jobs in a single request.
     *
     * @param array[] $jobs Each element: ['type' => string, 'args' => array, ...options]
     * @return Job[]
     */
    public function enqueueBatch(array $jobs): array
    {
        $payload = array_map(function (array $job) {
            return self::buildJobPayload(
                $job['type'],
                $job['args'] ?? [],
                array_diff_key($job, array_flip(['type', 'args'])),
            );
        }, $jobs);

        $response = $this->transport->post('/ojs/v1/jobs/batch', ['jobs' => $payload]);
        return array_map(fn(array $j) => Job::fromArray($j), $response['jobs'] ?? []);
    }

    // ── Job Operations ──────────────────────────────────────

    /**
     * Get a job by ID.
     */
    public function getJob(string $jobId): Job
    {
        $response = $this->transport->get("/ojs/v1/jobs/{$jobId}");
        return Job::fromArray($response);
    }

    /**
     * Cancel a job.
     */
    public function cancel(string $jobId): Job
    {
        $response = $this->transport->delete("/ojs/v1/jobs/{$jobId}");
        return Job::fromArray($response);
    }

    // ── Queue Management ────────────────────────────────────

    /**
     * List all queues.
     *
     * @return string[]
     */
    public function getQueues(): array
    {
        $response = $this->transport->get('/ojs/v1/queues');
        return $response['queues'] ?? [];
    }

    /**
     * Get statistics for a specific queue.
     */
    public function getQueueStats(string $queue): QueueStats
    {
        $response = $this->transport->get("/ojs/v1/queues/{$queue}/stats");
        return QueueStats::fromArray($response);
    }

    /**
     * Pause a queue (workers stop fetching).
     */
    public function pauseQueue(string $queue): void
    {
        $this->transport->post("/ojs/v1/queues/{$queue}/pause");
    }

    /**
     * Resume a paused queue.
     */
    public function resumeQueue(string $queue): void
    {
        $this->transport->post("/ojs/v1/queues/{$queue}/resume");
    }

    // ── Dead Letter Queue ───────────────────────────────────

    /**
     * List dead-lettered jobs.
     *
     * @return Job[]
     */
    public function getDeadLetterJobs(string $queue = 'default', int $limit = 25): array
    {
        $response = $this->transport->get('/ojs/v1/dead-letter', [
            'queue' => $queue,
            'limit' => $limit,
        ]);
        return array_map(fn(array $j) => Job::fromArray($j), $response['jobs'] ?? []);
    }

    /**
     * Retry a dead-lettered job.
     */
    public function retryDeadLetter(string $jobId): Job
    {
        $response = $this->transport->post("/ojs/v1/dead-letter/{$jobId}/retry");
        return Job::fromArray($response);
    }

    /**
     * Permanently discard a dead-lettered job.
     */
    public function discardDeadLetter(string $jobId): void
    {
        $this->transport->delete("/ojs/v1/dead-letter/{$jobId}");
    }

    // ── Cron Jobs ───────────────────────────────────────────

    /**
     * Register a cron job.
     */
    public function registerCronJob(CronJob $cron): array
    {
        return $this->transport->post('/ojs/v1/cron', $cron->toArray());
    }

    /**
     * List registered cron jobs.
     *
     * @return CronJob[]
     */
    public function listCronJobs(): array
    {
        $response = $this->transport->get('/ojs/v1/cron');
        return array_map(fn(array $c) => CronJob::fromArray($c), $response['cron_jobs'] ?? []);
    }

    /**
     * Unregister a cron job by name.
     */
    public function unregisterCronJob(string $name): void
    {
        $this->transport->delete("/ojs/v1/cron/{$name}");
    }

    // ── Workflows ───────────────────────────────────────────

    /**
     * Submit a workflow (chain, group, or batch).
     */
    public function workflow(array $definition): array
    {
        return $this->transport->post('/ojs/v1/workflows', $definition);
    }

    // ── Schema Registry ─────────────────────────────────────

    /**
     * Register a schema for job args validation.
     */
    public function registerSchema(string $uri, string $type, string $version, array $schema): array
    {
        return $this->transport->post('/ojs/v1/schemas', [
            'uri' => $uri,
            'type' => $type,
            'version' => $version,
            'schema' => $schema,
        ]);
    }

    /**
     * Get a registered schema.
     */
    public function getSchema(string $uri): array
    {
        return $this->transport->get("/ojs/v1/schemas/" . urlencode($uri));
    }

    /**
     * List registered schemas.
     */
    public function listSchemas(): array
    {
        return $this->transport->get('/ojs/v1/schemas');
    }

    /**
     * Delete a registered schema.
     */
    public function deleteSchema(string $uri): void
    {
        $this->transport->delete("/ojs/v1/schemas/" . urlencode($uri));
    }

    // ── Health & Manifest ───────────────────────────────────

    /**
     * Check backend health.
     */
    public function health(): array
    {
        return $this->transport->get('/ojs/v1/health');
    }

    /**
     * Get the backend conformance manifest.
     */
    public function manifest(): array
    {
        return $this->transport->get('/ojs/manifest');
    }

    // ── Events (SSE) ────────────────────────────────────────

    /**
     * Subscribe to events for a specific job via SSE.
     */
    public function subscribeJob(string $jobId, callable $callback): SSESubscription
    {
        return SSESubscription::forJob(
            $this->getBaseUrl(),
            $jobId,
            $callback,
            $this->getAuthToken(),
        );
    }

    /**
     * Subscribe to events for a queue via SSE.
     */
    public function subscribeQueue(string $queue, callable $callback): SSESubscription
    {
        return SSESubscription::forQueue(
            $this->getBaseUrl(),
            $queue,
            $callback,
            $this->getAuthToken(),
        );
    }

    /**
     * Subscribe to all events via SSE.
     */
    public function subscribeAll(callable $callback): SSESubscription
    {
        return SSESubscription::forAll(
            $this->getBaseUrl(),
            $callback,
            $this->getAuthToken(),
        );
    }

    // ── Internal ────────────────────────────────────────────

    public function getTransport(): Transport
    {
        return $this->transport;
    }

    private function getBaseUrl(): string
    {
        if ($this->transport instanceof HttpTransport) {
            return (new \ReflectionProperty(HttpTransport::class, 'baseUrl'))->getValue($this->transport);
        }
        return '';
    }

    private function getAuthToken(): ?string
    {
        if ($this->transport instanceof HttpTransport) {
            $headers = (new \ReflectionProperty(HttpTransport::class, 'headers'))->getValue($this->transport);
            $auth = $headers['Authorization'] ?? null;
            return $auth !== null ? str_replace('Bearer ', '', $auth) : null;
        }
        return null;
    }

    private static function buildJobPayload(string $type, array $args, array $options): array
    {
        $body = ['type' => $type, 'args' => $args];

        if (isset($options['queue'])) {
            $body['queue'] = $options['queue'];
        }
        if (isset($options['priority'])) {
            $body['priority'] = (int) $options['priority'];
        }
        if (isset($options['timeout'])) {
            $body['timeout'] = (int) $options['timeout'];
        }
        if (isset($options['meta'])) {
            $body['meta'] = $options['meta'];
        }
        if (isset($options['scheduled_at'])) {
            $body['scheduled_at'] = $options['scheduled_at'];
        }
        if (isset($options['expires_at'])) {
            $body['expires_at'] = $options['expires_at'];
        }
        if (isset($options['schema'])) {
            $body['schema'] = $options['schema'];
        }
        if (isset($options['retry'])) {
            $body['retry'] = $options['retry'] instanceof RetryPolicy
                ? $options['retry']->toArray()
                : $options['retry'];
        }
        if (isset($options['unique'])) {
            $body['unique'] = $options['unique'] instanceof UniquePolicy
                ? $options['unique']->toArray()
                : $options['unique'];
        }

        return $body;
    }
}

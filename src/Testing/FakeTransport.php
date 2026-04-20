<?php

declare(strict_types=1);

namespace OpenJobSpec\Testing;

use OpenJobSpec\{CronJob, Job, JobContext, QueueStats, Transport};

/**
 * In-memory fake transport for testing OJS client and worker code
 * without a running backend.
 *
 * Usage:
 *   $transport = new FakeTransport();
 *   $client = new Client('http://fake', ['transport' => $transport]);
 *   $client->enqueue('email.send', ['user@example.com']);
 *   assert($transport->enqueued('email.send')); // true
 */
class FakeTransport implements Transport
{
    /** @var FakeJob[] */
    private array $jobs = [];

    /** @var CronJob[] */
    private array $cronJobs = [];

    /** @var array<string, array> */
    private array $schemas = [];

    /** @var array<string, QueueStats> */
    private array $queues = [];

    private int $counter = 0;

    public function post(string $path, array $body = []): array
    {
        return match (true) {
            $path === '/ojs/v1/jobs' => $this->handleEnqueue($body),
            $path === '/ojs/v1/jobs/batch' => $this->handleBatchEnqueue($body),
            str_starts_with($path, '/ojs/v1/workers/fetch') => $this->handleFetch($body),
            str_starts_with($path, '/ojs/v1/workers/ack') => $this->handleAck($body),
            str_starts_with($path, '/ojs/v1/workers/nack') => $this->handleNack($body),
            str_starts_with($path, '/ojs/v1/workers/heartbeat') => ['directive' => 'running'],
            str_starts_with($path, '/ojs/v1/dead-letter/') && str_ends_with($path, '/retry') => $this->handleRetryDeadLetter($path),
            $path === '/ojs/v1/cron' => $this->handleRegisterCron($body),
            $path === '/ojs/v1/workflows' => $this->handleWorkflow($body),
            $path === '/ojs/v1/schemas' => $this->handleRegisterSchema($body),
            str_contains($path, '/pause') => [],
            str_contains($path, '/resume') => [],
            default => [],
        };
    }

    public function get(string $path, array $query = []): array
    {
        return match (true) {
            preg_match('#^/ojs/v1/jobs/(.+)$#', $path, $m) === 1 => $this->handleGetJob($m[1]),
            $path === '/ojs/v1/queues' => ['queues' => array_keys($this->queues) ?: ['default']],
            str_starts_with($path, '/ojs/v1/queues/') && str_ends_with($path, '/stats') => $this->handleQueueStats($path),
            $path === '/ojs/v1/dead-letter' => ['jobs' => $this->getDeadLetterJobs()],
            $path === '/ojs/v1/cron' => ['cron_jobs' => array_map(fn(CronJob $c) => $c->toArray(), $this->cronJobs)],
            $path === '/ojs/v1/health' => ['status' => 'ok'],
            $path === '/ojs/manifest' => ['version' => '1.0', 'level' => 4],
            $path === '/ojs/v1/schemas' => ['schemas' => $this->schemas],
            str_starts_with($path, '/ojs/v1/schemas/') => $this->handleGetSchema($path),
            default => [],
        };
    }

    public function delete(string $path): array
    {
        return match (true) {
            preg_match('#^/ojs/v1/jobs/(.+)$#', $path, $m) === 1 => $this->handleCancel($m[1]),
            str_starts_with($path, '/ojs/v1/cron/') => $this->handleUnregisterCron($path),
            str_starts_with($path, '/ojs/v1/dead-letter/') => $this->handleDiscardDeadLetter($path),
            str_starts_with($path, '/ojs/v1/schemas/') => $this->handleDeleteSchema($path),
            default => [],
        };
    }

    // ── Query Methods ───────────────────────────────────────

    /**
     * Check if a job of the given type was enqueued.
     */
    public function enqueued(string $type, ?array $args = null, ?string $queue = null): bool
    {
        return $this->findEnqueued($type, $args, $queue) !== null;
    }

    /**
     * Count enqueued jobs matching criteria.
     */
    public function enqueuedCount(?string $type = null, ?string $queue = null): int
    {
        return count($this->allEnqueued($type, $queue));
    }

    /**
     * Get all enqueued jobs, optionally filtered.
     *
     * @return FakeJob[]
     */
    public function allEnqueued(?string $type = null, ?string $queue = null): array
    {
        return array_values(array_filter($this->jobs, function (FakeJob $job) use ($type, $queue) {
            if ($type !== null && $job->type !== $type) {
                return false;
            }
            if ($queue !== null && $job->queue !== $queue) {
                return false;
            }
            return true;
        }));
    }

    /**
     * Check if a job was completed.
     */
    public function completed(string $type): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->type === $type && $job->state === 'completed') {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a job was failed/discarded.
     */
    public function failed(string $type): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->type === $type && $job->state === 'discarded') {
                return true;
            }
        }
        return false;
    }

    /**
     * Drain all available jobs through registered handlers.
     *
     * @param array<string, callable> $handlers Map of type => handler
     * @return int Number of jobs processed
     */
    public function drain(array $handlers, int $maxJobs = 100): int
    {
        $processed = 0;
        foreach ($this->jobs as $job) {
            if ($job->state !== 'available' || $processed >= $maxJobs) {
                continue;
            }

            $handler = $handlers[$job->type] ?? null;
            if ($handler === null) {
                continue;
            }

            $job->state = 'active';
            $ctx = new JobContext(Job::fromArray($job->toArray()));

            try {
                $result = $handler($ctx);
                $job->state = 'completed';
                $job->result = $result;
            } catch (\Throwable $e) {
                $job->state = 'discarded';
                $job->error = ['type' => get_class($e), 'message' => $e->getMessage()];
            }

            $processed++;
        }
        return $processed;
    }

    /**
     * Clear all stored jobs and state.
     */
    public function clear(): void
    {
        $this->jobs = [];
        $this->cronJobs = [];
        $this->schemas = [];
        $this->queues = [];
        $this->counter = 0;
    }

    // ── Internal Handlers ───────────────────────────────────

    private function handleEnqueue(array $body): array
    {
        $job = new FakeJob(
            id: $this->nextId(),
            type: $body['type'],
            args: $body['args'] ?? [],
            queue: $body['queue'] ?? 'default',
            priority: $body['priority'] ?? 0,
            meta: $body['meta'] ?? [],
            scheduledAt: $body['scheduled_at'] ?? null,
            retry: $body['retry'] ?? null,
            unique: $body['unique'] ?? null,
            schema: $body['schema'] ?? null,
            timeout: $body['timeout'] ?? null,
        );
        $this->jobs[$job->id] = $job;
        $this->trackQueue($job->queue);
        return $job->toArray();
    }

    private function handleBatchEnqueue(array $body): array
    {
        $results = [];
        foreach ($body['jobs'] ?? [] as $jobBody) {
            $results[] = $this->handleEnqueue($jobBody);
        }
        return ['jobs' => $results];
    }

    private function handleFetch(array $body): array
    {
        $queues = $body['queues'] ?? ['default'];
        $count = $body['count'] ?? 1;
        $fetched = [];

        foreach ($this->jobs as $job) {
            if (count($fetched) >= $count) {
                break;
            }
            if ($job->state === 'available' && in_array($job->queue, $queues, true)) {
                $job->state = 'active';
                $job->attempt++;
                $fetched[] = $job->toArray();
            }
        }

        return ['jobs' => $fetched];
    }

    private function handleAck(array $body): array
    {
        $jobId = $body['job_id'] ?? '';
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->state = 'completed';
            if (isset($body['result'])) {
                $this->jobs[$jobId]->result = $body['result'];
            }
        }
        return [];
    }

    private function handleNack(array $body): array
    {
        $jobId = $body['job_id'] ?? '';
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->state = 'discarded';
            $this->jobs[$jobId]->error = $body['error'] ?? null;
        }
        return [];
    }

    private function handleGetJob(string $jobId): array
    {
        $job = $this->jobs[$jobId] ?? null;
        if ($job === null) {
            throw new \OpenJobSpec\NotFoundError("Job not found: {$jobId}");
        }
        return $job->toArray();
    }

    private function handleCancel(string $jobId): array
    {
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->state = 'cancelled';
        }
        return $this->jobs[$jobId]?->toArray() ?? [];
    }

    private function handleQueueStats(string $path): array
    {
        preg_match('#/ojs/v1/queues/(.+)/stats#', $path, $m);
        $queue = $m[1] ?? 'default';
        $stats = ['name' => $queue, 'available' => 0, 'active' => 0, 'completed' => 0, 'discarded' => 0];
        foreach ($this->jobs as $job) {
            if ($job->queue === $queue && isset($stats[$job->state])) {
                $stats[$job->state]++;
            }
        }
        return $stats;
    }

    private function getDeadLetterJobs(): array
    {
        return array_values(array_map(
            fn(FakeJob $j) => $j->toArray(),
            array_filter($this->jobs, fn(FakeJob $j) => $j->state === 'discarded'),
        ));
    }

    private function handleRetryDeadLetter(string $path): array
    {
        preg_match('#/ojs/v1/dead-letter/(.+)/retry#', $path, $m);
        $jobId = $m[1] ?? '';
        if (isset($this->jobs[$jobId])) {
            $this->jobs[$jobId]->state = 'available';
            $this->jobs[$jobId]->error = null;
        }
        return $this->jobs[$jobId]?->toArray() ?? [];
    }

    private function handleDiscardDeadLetter(string $path): array
    {
        preg_match('#/ojs/v1/dead-letter/(.+)$#', $path, $m);
        $jobId = $m[1] ?? '';
        unset($this->jobs[$jobId]);
        return [];
    }

    private function handleRegisterCron(array $body): array
    {
        $cron = CronJob::fromArray($body);
        $this->cronJobs[$cron->name] = $cron;
        return $body;
    }

    private function handleUnregisterCron(string $path): array
    {
        $name = basename($path);
        unset($this->cronJobs[$name]);
        return [];
    }

    private function handleWorkflow(array $body): array
    {
        return array_merge($body, ['id' => $this->nextId(), 'state' => 'active']);
    }

    private function handleRegisterSchema(array $body): array
    {
        $uri = $body['uri'] ?? '';
        $this->schemas[$uri] = $body;
        return $body;
    }

    private function handleGetSchema(string $path): array
    {
        $uri = urldecode(basename($path));
        return $this->schemas[$uri] ?? [];
    }

    private function handleDeleteSchema(string $path): array
    {
        $uri = urldecode(basename($path));
        unset($this->schemas[$uri]);
        return [];
    }

    private function trackQueue(string $queue): void
    {
        if (!isset($this->queues[$queue])) {
            $this->queues[$queue] = true;
        }
    }

    private function nextId(): string
    {
        $this->counter++;
        return sprintf('fake-%06d', $this->counter);
    }
}

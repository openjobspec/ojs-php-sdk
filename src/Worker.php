<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * OJS Worker — poll-based job consumer with middleware chain.
 *
 * Fetches jobs from an OJS backend, dispatches them through a middleware
 * chain to registered handlers, and reports results back (ACK/NACK).
 */
class Worker
{
    private Transport $transport;
    private MiddlewareChain $middleware;

    /** @var array<string, callable> */
    private array $handlers = [];
    private bool $running = false;
    private bool $quiet = false;

    /** @var callable[] */
    private array $eventListeners = [];

    // Configuration
    private array $queues;
    private int $concurrency;
    private float $pollInterval;
    private float $heartbeatInterval;
    private float $shutdownTimeout;

    private float $lastHeartbeat = 0;

    public function __construct(string $url, array $options = [])
    {
        if (isset($options['transport']) && $options['transport'] instanceof Transport) {
            $this->transport = $options['transport'];
        } else {
            $this->transport = new HttpTransport($url, $options);
        }

        $this->middleware = new MiddlewareChain();
        $this->queues = $options['queues'] ?? [];
        $this->concurrency = $options['concurrency'] ?? 5;
        $this->pollInterval = $options['poll_interval'] ?? 2.0;
        $this->heartbeatInterval = $options['heartbeat_interval'] ?? 15.0;
        $this->shutdownTimeout = $options['shutdown_timeout'] ?? 25.0;
    }

    /**
     * Register a handler for a job type.
     */
    public function register(string $type, callable $handler): self
    {
        $this->handlers[$type] = $handler;
        return $this;
    }

    /**
     * Add middleware to the chain (outermost first).
     */
    public function use(string $name, Middleware|callable $middleware): self
    {
        $this->middleware->add($name, $middleware);
        return $this;
    }

    /**
     * Access the middleware chain for advanced configuration.
     */
    public function middlewareChain(): MiddlewareChain
    {
        return $this->middleware;
    }

    /**
     * Register an event listener.
     */
    public function on(string $event, callable $listener): self
    {
        $this->eventListeners[$event][] = $listener;
        return $this;
    }

    /**
     * Start the worker polling loop. Blocks until stop() is called.
     */
    public function start(): void
    {
        $this->running = true;
        $this->quiet = false;
        $this->lastHeartbeat = microtime(true);
        $this->emit(Event::WORKER_STARTED, ['queues' => $this->getActiveQueues()]);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->stop());
        pcntl_signal(SIGINT, fn() => $this->stop());
        pcntl_signal(SIGQUIT, fn() => $this->quiet());

        while ($this->running) {
            if (!$this->quiet) {
                $this->poll();
            }

            $now = microtime(true);
            if ($now - $this->lastHeartbeat >= $this->heartbeatInterval) {
                $this->sendHeartbeat();
                $this->lastHeartbeat = $now;
            }

            usleep((int) ($this->pollInterval * 1_000_000));
        }

        $this->emit(Event::WORKER_STOPPED, []);
    }

    /**
     * Stop the worker gracefully: finish in-flight jobs, then exit.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Enter quiet mode: stop fetching new jobs but finish in-flight ones.
     */
    public function quiet(): void
    {
        $this->quiet = true;
        $this->emit(Event::WORKER_QUIET, []);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function isQuiet(): bool
    {
        return $this->quiet;
    }

    /**
     * Process a single batch of jobs (useful for testing).
     */
    public function processOnce(): void
    {
        $this->poll();
    }

    // ── Internal ────────────────────────────────────────────

    private function poll(): void
    {
        $activeQueues = $this->getActiveQueues();
        if ($activeQueues === []) {
            return;
        }

        try {
            $response = $this->transport->post('/ojs/v1/workers/fetch', [
                'queues' => $activeQueues,
                'count' => $this->concurrency,
            ]);
        } catch (OjsException) {
            return;
        }

        foreach ($response['jobs'] ?? [] as $jobData) {
            $job = Job::fromArray($jobData);
            $this->dispatch($job);
        }
    }

    private function dispatch(Job $job): void
    {
        $handler = $this->handlers[$job->type] ?? null;
        if ($handler === null) {
            $this->nack($job, 'no_handler', "No handler registered for type: {$job->type}");
            return;
        }

        $ctx = new JobContext($job, $this->transport);
        $this->emit(Event::JOB_STARTED, ['job_id' => $job->id, 'type' => $job->type]);

        try {
            $result = $this->middleware->invoke($ctx, function (JobContext $c) use ($handler): mixed {
                return $handler($c);
            });

            $this->ack($job, $result);
            $this->emit(Event::JOB_COMPLETED, ['job_id' => $job->id, 'type' => $job->type]);
        } catch (\Throwable $e) {
            $this->nack($job, get_class($e), $e->getMessage(), $this->formatBacktrace($e));
            $this->emit(Event::JOB_FAILED, [
                'job_id' => $job->id,
                'type' => $job->type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ack(Job $job, mixed $result = null): void
    {
        $body = ['job_id' => $job->id];
        if ($result !== null) {
            $body['result'] = $result;
        }

        try {
            $this->transport->post('/ojs/v1/workers/ack', $body);
        } catch (OjsException) {
            // Best-effort ACK; server will eventually time out the job
        }
    }

    private function nack(Job $job, string $errorType, string $message, array $backtrace = []): void
    {
        $error = [
            'type' => $errorType,
            'message' => $message,
        ];
        if ($backtrace !== []) {
            $error['backtrace'] = $backtrace;
        }

        try {
            $this->transport->post('/ojs/v1/workers/nack', [
                'job_id' => $job->id,
                'error' => $error,
            ]);
        } catch (OjsException) {
            // Best-effort NACK
        }
    }

    private function sendHeartbeat(): void
    {
        try {
            $response = $this->transport->post('/ojs/v1/workers/heartbeat', [
                'queues' => $this->getActiveQueues(),
            ]);

            $directive = $response['directive'] ?? 'running';
            if ($directive === 'quiet') {
                $this->quiet();
            } elseif ($directive === 'terminate') {
                $this->stop();
            }

            $this->emit(Event::WORKER_HEARTBEAT, ['directive' => $directive]);
        } catch (OjsException) {
            // Best-effort heartbeat
        }
    }

    private function getActiveQueues(): array
    {
        if ($this->queues !== []) {
            return $this->queues;
        }
        $registered = array_keys($this->handlers);
        return $registered !== [] ? $registered : ['default'];
    }

    private function emit(string $eventType, array $data): void
    {
        $listeners = $this->eventListeners[$eventType] ?? [];
        $allListeners = $this->eventListeners['*'] ?? [];

        $event = new Event(type: $eventType, data: $data, time: date('c'));

        foreach ([...$listeners, ...$allListeners] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable) {
                // Don't let listener errors break the worker
            }
        }
    }

    private function formatBacktrace(\Throwable $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? '<internal>';
            $line = $frame['line'] ?? 0;
            $func = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            $trace[] = "{$file}:{$line} in {$func}";
        }
        return array_slice($trace, 0, 20);
    }
}

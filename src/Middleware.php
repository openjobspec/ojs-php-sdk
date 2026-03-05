<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Middleware interface for the OJS worker middleware chain.
 */
interface Middleware
{
    /**
     * Process a job through the middleware chain.
     *
     * @param JobContext $ctx The job context (includes job, store, heartbeat)
     * @param callable $next The next middleware/handler in the chain
     * @return mixed
     */
    public function handle(JobContext $ctx, callable $next): mixed;
}

/**
 * Named middleware chain with ordered execution.
 *
 * Middleware wraps the handler in an onion-style chain: the first middleware
 * added is the outermost layer.
 */
class MiddlewareChain
{
    /** @var array<string, Middleware|callable> */
    private array $stack = [];

    /**
     * Add middleware to the end of the chain.
     */
    public function add(string $name, Middleware|callable $middleware): self
    {
        $this->stack[$name] = $middleware;
        return $this;
    }

    /**
     * Add middleware to the beginning of the chain.
     */
    public function prepend(string $name, Middleware|callable $middleware): self
    {
        $this->stack = [$name => $middleware] + $this->stack;
        return $this;
    }

    /**
     * Insert middleware before a named middleware.
     */
    public function insertBefore(string $target, string $name, Middleware|callable $middleware): self
    {
        $new = [];
        foreach ($this->stack as $k => $v) {
            if ($k === $target) {
                $new[$name] = $middleware;
            }
            $new[$k] = $v;
        }
        $this->stack = $new;
        return $this;
    }

    /**
     * Insert middleware after a named middleware.
     */
    public function insertAfter(string $target, string $name, Middleware|callable $middleware): self
    {
        $new = [];
        foreach ($this->stack as $k => $v) {
            $new[$k] = $v;
            if ($k === $target) {
                $new[$name] = $middleware;
            }
        }
        $this->stack = $new;
        return $this;
    }

    /**
     * Remove middleware by name.
     */
    public function remove(string $name): self
    {
        unset($this->stack[$name]);
        return $this;
    }

    public function has(string $name): bool
    {
        return isset($this->stack[$name]);
    }

    /**
     * Execute the chain with a terminal handler.
     */
    public function invoke(JobContext $ctx, callable $terminal): mixed
    {
        $chain = $terminal;

        foreach (array_reverse($this->stack) as $mw) {
            $next = $chain;
            if ($mw instanceof Middleware) {
                $chain = static fn(JobContext $c) => $mw->handle($c, fn() => $next($c));
            } else {
                $chain = static fn(JobContext $c) => $mw($c, fn() => $next($c));
            }
        }

        return $chain($ctx);
    }
}

/**
 * Logging middleware — logs job start/complete/failure with timing.
 */
class LoggingMiddleware implements Middleware
{
    public function handle(JobContext $ctx, callable $next): mixed
    {
        $start = hrtime(true);
        $job = $ctx->job;
        echo "[OJS] Starting job {$job->type} ({$job->id}) attempt={$job->attempt}\n";

        try {
            $result = $next($ctx);
            $elapsed = round((hrtime(true) - $start) / 1e6, 2);
            echo "[OJS] Completed job {$job->type} ({$job->id}) in {$elapsed}ms\n";
            return $result;
        } catch (\Throwable $e) {
            $elapsed = round((hrtime(true) - $start) / 1e6, 2);
            echo "[OJS] Failed job {$job->type} ({$job->id}) in {$elapsed}ms: {$e->getMessage()}\n";
            throw $e;
        }
    }
}

/**
 * Timeout middleware — enforces a maximum execution time.
 */
class TimeoutMiddleware implements Middleware
{
    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function handle(JobContext $ctx, callable $next): mixed
    {
        $previous = set_time_limit($this->timeoutSeconds);
        try {
            return $next($ctx);
        } finally {
            set_time_limit($previous !== false ? (int) $previous : 0);
        }
    }
}

/**
 * Metrics middleware — records job processing metrics via a callback.
 */
class MetricsMiddleware implements Middleware
{
    /** @var callable(string $type, float $duration, bool $success, array $tags): void */
    private $recorder;

    public function __construct(callable $recorder)
    {
        $this->recorder = $recorder;
    }

    public function handle(JobContext $ctx, callable $next): mixed
    {
        $start = hrtime(true);
        $success = true;

        try {
            return $next($ctx);
        } catch (\Throwable $e) {
            $success = false;
            throw $e;
        } finally {
            $duration = (hrtime(true) - $start) / 1e9;
            ($this->recorder)(
                $ctx->job->type,
                $duration,
                $success,
                ['queue' => $ctx->job->queue, 'attempt' => $ctx->job->attempt],
            );
        }
    }
}

/**
 * Recovery middleware — catches panics/errors and converts to job failures.
 */
class RecoveryMiddleware implements Middleware
{
    public function handle(JobContext $ctx, callable $next): mixed
    {
        try {
            return $next($ctx);
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}

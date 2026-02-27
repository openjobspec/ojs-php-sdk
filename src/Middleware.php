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
     * @param Job $job The job being processed
     * @param callable $next The next middleware/handler in the chain
     * @return mixed
     */
    public function handle(Job $job, callable $next): mixed;
}

/**
 * Logging middleware — logs job start/complete/failure.
 */
class LoggingMiddleware implements Middleware
{
    public function handle(Job $job, callable $next): mixed
    {
        $start = microtime(true);
        echo "[OJS] Starting job {$job->type} ({$job->id})\n";

        try {
            $result = $next($job);
            $elapsed = round((microtime(true) - $start) * 1000, 2);
            echo "[OJS] Completed job {$job->type} ({$job->id}) in {$elapsed}ms\n";
            return $result;
        } catch (\Throwable $e) {
            $elapsed = round((microtime(true) - $start) * 1000, 2);
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
    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds = 30)
    {
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function handle(Job $job, callable $next): mixed
    {
        set_time_limit($this->timeoutSeconds);
        return $next($job);
    }
}

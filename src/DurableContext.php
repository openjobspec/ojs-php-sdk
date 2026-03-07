<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Durable execution context for OJS jobs.
 *
 * Provides checkpoint-based state persistence and deterministic replay
 * for long-running or crash-recoverable job handlers. Non-deterministic
 * operations (time, randomness, side effects) are recorded on first
 * execution and replayed from the log on recovery.
 *
 * Usage:
 *   $ctx = DurableContext::create($transport, $job);
 *   $now = $ctx->now();             // deterministic
 *   $id  = $ctx->random(16);        // deterministic
 *   $val = $ctx->sideEffect('key', fn() => expensiveCall());
 *   $ctx->save(['step' => 3, 'total' => $total]);
 */
class DurableContext
{
    private Transport $transport;
    private Job $job;

    /** @var list<mixed> Ordered replay log for deterministic operations */
    private array $replayLog = [];

    /** @var int Current position in the replay log during replay */
    private int $replayIndex = 0;

    /**
     * @param Transport $transport HTTP transport for checkpoint API calls
     * @param Job       $job       The job this context is bound to
     */
    public function __construct(Transport $transport, Job $job)
    {
        $this->transport = $transport;
        $this->job = $job;
    }

    /**
     * Create a DurableContext, loading any existing replay log from the checkpoint endpoint.
     *
     * If a prior checkpoint exists with a `replay_log` field, the log is restored
     * so that subsequent calls to now(), random(), and sideEffect() replay
     * recorded values before generating new ones.
     *
     * @param Transport $transport HTTP transport for checkpoint API calls
     * @param Job       $job       The job this context is bound to
     * @return self Hydrated context ready for deterministic execution
     */
    public static function create(Transport $transport, Job $job): self
    {
        $ctx = new self($transport, $job);

        $state = $ctx->resume();
        if (is_array($state) && isset($state['replay_log']) && is_array($state['replay_log'])) {
            $ctx->replayLog = array_values($state['replay_log']);
        }

        return $ctx;
    }

    /**
     * Save checkpoint state for the job.
     *
     * Sends POST to /ojs/v1/jobs/{id}/checkpoint with {"state": state}.
     * The Transport interface does not expose PUT, so POST is used with
     * upsert semantics on the server side.
     *
     * @param mixed $state Arbitrary serializable state to persist
     * @throws OjsException On transport or server errors
     */
    public function save(mixed $state): void
    {
        $this->transport->post(
            "/ojs/v1/jobs/{$this->job->id}/checkpoint",
            ['state' => $state],
        );
    }

    /**
     * Resume from checkpoint.
     *
     * Sends GET to /ojs/v1/jobs/{id}/checkpoint. Returns the saved state,
     * or null if no checkpoint exists (404).
     *
     * @return mixed The previously saved state, or null
     * @throws OjsException On non-404 transport or server errors
     */
    public function resume(): mixed
    {
        try {
            $response = $this->transport->get("/ojs/v1/jobs/{$this->job->id}/checkpoint");
            return $response['state'] ?? null;
        } catch (OjsException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Delete the checkpoint for the job.
     *
     * Sends DELETE to /ojs/v1/jobs/{id}/checkpoint. Idempotent — does not
     * throw if no checkpoint exists (404 is silently ignored).
     *
     * @throws OjsException On non-404 transport or server errors
     */
    public function delete(): void
    {
        try {
            $this->transport->delete("/ojs/v1/jobs/{$this->job->id}/checkpoint");
        } catch (OjsException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    // ── Deterministic Operations ────────────────────────────

    /**
     * Get the current time deterministically.
     *
     * On first execution, records the real wall-clock time into the replay log.
     * On replay (after crash recovery), returns the previously recorded time
     * without consulting the system clock.
     *
     * @return \DateTimeImmutable Deterministic timestamp
     */
    public function now(): \DateTimeImmutable
    {
        if ($this->replayIndex < count($this->replayLog)) {
            $entry = $this->replayLog[$this->replayIndex];
            $this->replayIndex++;
            return new \DateTimeImmutable($entry);
        }

        $now = new \DateTimeImmutable();
        $this->replayLog[] = $now->format(\DateTimeInterface::RFC3339_EXTENDED);
        $this->replayIndex++;
        return $now;
    }

    /**
     * Generate deterministic random hex bytes.
     *
     * On first execution, generates cryptographically secure random bytes
     * and records the hex string. On replay, returns the recorded value.
     *
     * @param int $numBytes Number of random bytes (output hex string is 2× this length)
     * @return string Hex-encoded random bytes
     */
    public function random(int $numBytes): string
    {
        if ($this->replayIndex < count($this->replayLog)) {
            $entry = $this->replayLog[$this->replayIndex];
            $this->replayIndex++;
            return $entry;
        }

        $hex = bin2hex(random_bytes($numBytes));
        $this->replayLog[] = $hex;
        $this->replayIndex++;
        return $hex;
    }

    /**
     * Execute a side effect deterministically.
     *
     * On first execution, invokes the callable and records its return value
     * in the replay log. On replay, returns the recorded result without
     * executing the callable. Use this for any non-deterministic operation
     * (HTTP calls, database queries, file I/O, etc.).
     *
     * @param string   $key Descriptive key for debugging (not used for matching — order-based)
     * @param callable $fn  Callable that produces the side effect result
     * @return mixed The result of the callable (or replayed value)
     */
    public function sideEffect(string $key, callable $fn): mixed
    {
        if ($this->replayIndex < count($this->replayLog)) {
            $entry = $this->replayLog[$this->replayIndex];
            $this->replayIndex++;
            return $entry;
        }

        $result = $fn();
        $this->replayLog[] = $result;
        $this->replayIndex++;
        return $result;
    }

    // ── Accessors ───────────────────────────────────────────

    /**
     * Get the current replay log for persistence.
     *
     * Include this in your save() calls to enable replay after recovery:
     *   $ctx->save(['replay_log' => $ctx->getReplayLog(), 'step' => $step]);
     *
     * @return list<mixed> The ordered replay log entries
     */
    public function getReplayLog(): array
    {
        return $this->replayLog;
    }

    /**
     * Get the job this context is bound to.
     */
    public function getJob(): Job
    {
        return $this->job;
    }
}

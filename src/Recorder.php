<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Source code location for a trace entry.
 */
class SourceMap
{
    public function __construct(
        public readonly string $gitSHA,
        public readonly string $filePath,
        public readonly int $line,
    ) {}
}

/**
 * A single recorded function call.
 */
class TraceEntry
{
    public function __construct(
        public readonly string $funcName,
        public readonly string $args,
        public readonly string $result,
        public readonly int $durationMs,
        public ?SourceMap $sourceMap = null,
        public readonly string $timestamp = '',
        public readonly ?string $error = null,
    ) {}
}

/**
 * Captures execution traces for OJS job handlers.
 *
 * Usage:
 *   $recorder = new Recorder();
 *   $start = microtime(true);
 *   $result = $handler($args);
 *   $elapsed = (int)((microtime(true) - $start) * 1000);
 *   $recorder->recordCall('handler', $args, $result, $elapsed);
 *   $recorder->attachSourceMap('abc123', 'Handler.php', 42);
 *   $trace = $recorder->trace();
 */
class Recorder
{
    /** @var TraceEntry[] */
    private array $entries = [];

    /** Record a successful function call. */
    public function recordCall(string $funcName, mixed $args, mixed $result, int $durationMs): void
    {
        $this->entries[] = new TraceEntry(
            funcName: $funcName,
            args: json_encode($args) ?: '',
            result: json_encode($result) ?: '',
            durationMs: $durationMs,
            timestamp: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /** Record a failed function call. */
    public function recordError(string $funcName, mixed $args, \Throwable|string $error, int $durationMs): void
    {
        $this->entries[] = new TraceEntry(
            funcName: $funcName,
            args: json_encode($args) ?: '',
            result: '',
            durationMs: $durationMs,
            timestamp: gmdate('Y-m-d\TH:i:s\Z'),
            error: $error instanceof \Throwable ? $error->getMessage() : (string)$error,
        );
    }

    /** Attach source location to the most recent trace entry. */
    public function attachSourceMap(string $gitSHA, string $filePath, int $line): void
    {
        if (empty($this->entries)) return;
        $this->entries[array_key_last($this->entries)]->sourceMap = new SourceMap($gitSHA, $filePath, $line);
    }

    /** Return a copy of all recorded trace entries. */
    public function trace(): array
    {
        return array_map(fn(TraceEntry $e) => clone $e, $this->entries);
    }

    /** Number of recorded entries. */
    public function count(): int
    {
        return count($this->entries);
    }

    /** Clear all recorded entries. */
    public function reset(): void
    {
        $this->entries = [];
    }
}

<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * OpenTelemetry middleware — adds distributed tracing to job processing.
 *
 * If the OpenTelemetry SDK is installed, this middleware creates spans for
 * each job execution and propagates trace context via job metadata.
 *
 * Requires: open-telemetry/sdk (optional dependency)
 */
class OpenTelemetryMiddleware implements Middleware
{
    private ?object $tracer;

    public function __construct(?object $tracer = null)
    {
        $this->tracer = $tracer;
    }

    public function handle(JobContext $ctx, callable $next): mixed
    {
        if ($this->tracer === null || !$this->isOtelAvailable()) {
            return $next($ctx);
        }

        $job = $ctx->job;
        $span = $this->startSpan($job);

        try {
            $result = $next($ctx);
            $this->endSpan($span, 'ok');
            return $result;
        } catch (\Throwable $e) {
            $this->endSpan($span, 'error', $e);
            throw $e;
        }
    }

    private function isOtelAvailable(): bool
    {
        return interface_exists('\\OpenTelemetry\\API\\Trace\\TracerInterface');
    }

    private function startSpan(Job $job): ?object
    {
        if ($this->tracer === null || !method_exists($this->tracer, 'spanBuilder')) {
            return null;
        }

        try {
            $builder = $this->tracer->spanBuilder("ojs.process {$job->type}");
            $builder->setAttribute('ojs.job.id', $job->id);
            $builder->setAttribute('ojs.job.type', $job->type);
            $builder->setAttribute('ojs.job.queue', $job->queue);
            $builder->setAttribute('ojs.job.attempt', $job->attempt);
            return $builder->startSpan();
        } catch (\Throwable) {
            return null;
        }
    }

    private function endSpan(?object $span, string $status, ?\Throwable $error = null): void
    {
        if ($span === null || !method_exists($span, 'end')) {
            return;
        }

        try {
            if ($error !== null && method_exists($span, 'recordException')) {
                $span->recordException($error);
            }
            if (method_exists($span, 'setStatus')) {
                $span->setStatus($status === 'ok' ? 1 : 2, $error?->getMessage() ?? '');
            }
            $span->end();
        } catch (\Throwable) {
            // Tracing failures must never break job processing
        }
    }
}

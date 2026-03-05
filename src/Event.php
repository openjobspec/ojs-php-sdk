<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Event represents an OJS lifecycle event (CloudEvents-compatible).
 */
class Event
{
    public const JOB_ENQUEUED = 'job.enqueued';
    public const JOB_STARTED = 'job.started';
    public const JOB_COMPLETED = 'job.completed';
    public const JOB_FAILED = 'job.failed';
    public const JOB_RETRYING = 'job.retrying';
    public const JOB_CANCELLED = 'job.cancelled';
    public const JOB_HEARTBEAT = 'job.heartbeat';
    public const JOB_SCHEDULED = 'job.scheduled';
    public const JOB_EXPIRED = 'job.expired';
    public const JOB_PROGRESS = 'job.progress';

    public const QUEUE_PAUSED = 'queue.paused';
    public const QUEUE_RESUMED = 'queue.resumed';

    public const WORKER_STARTED = 'worker.started';
    public const WORKER_STOPPED = 'worker.stopped';
    public const WORKER_QUIET = 'worker.quiet';
    public const WORKER_HEARTBEAT = 'worker.heartbeat';

    public const WORKFLOW_STARTED = 'workflow.started';
    public const WORKFLOW_COMPLETED = 'workflow.completed';
    public const WORKFLOW_FAILED = 'workflow.failed';
    public const WORKFLOW_STEP_COMPLETED = 'workflow.step.completed';

    public const CRON_TRIGGERED = 'cron.triggered';
    public const CRON_SKIPPED = 'cron.skipped';

    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
        public readonly ?string $id = null,
        public readonly ?string $source = null,
        public readonly ?string $time = null,
        public readonly ?string $subject = null,
    ) {}

    public function toArray(): array
    {
        $result = ['type' => $this->type, 'data' => $this->data];
        if ($this->id !== null) {
            $result['id'] = $this->id;
        }
        if ($this->source !== null) {
            $result['source'] = $this->source;
        }
        if ($this->time !== null) {
            $result['time'] = $this->time;
        }
        if ($this->subject !== null) {
            $result['subject'] = $this->subject;
        }
        return $result;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            data: $data['data'] ?? [],
            id: $data['id'] ?? null,
            source: $data['source'] ?? null,
            time: $data['time'] ?? null,
            subject: $data['subject'] ?? null,
        );
    }
}

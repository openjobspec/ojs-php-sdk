<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Immutable representation of an OJS job envelope.
 */
class Job
{
    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_AVAILABLE = 'available';
    public const STATE_PENDING = 'pending';
    public const STATE_ACTIVE = 'active';
    public const STATE_COMPLETED = 'completed';
    public const STATE_RETRYABLE = 'retryable';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_DISCARDED = 'discarded';

    public const TERMINAL_STATES = [self::STATE_COMPLETED, self::STATE_CANCELLED, self::STATE_DISCARDED];

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $args,
        public readonly string $queue = 'default',
        public readonly string $state = self::STATE_AVAILABLE,
        public readonly int $attempt = 0,
        public readonly int $priority = 0,
        public readonly array $meta = [],
        public readonly ?int $timeout = null,
        public readonly ?string $scheduledAt = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $enqueuedAt = null,
        public readonly ?string $startedAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?array $error = null,
        public readonly mixed $result = null,
        public readonly ?RetryPolicy $retryPolicy = null,
        public readonly ?UniquePolicy $uniquePolicy = null,
        public readonly ?string $schema = null,
        public readonly ?float $progress = null,
    ) {}

    public function isTerminal(): bool
    {
        return in_array($this->state, self::TERMINAL_STATES, true);
    }

    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'args' => $this->args,
            'queue' => $this->queue,
            'state' => $this->state,
            'attempt' => $this->attempt,
        ];
        if ($this->priority !== 0) {
            $data['priority'] = $this->priority;
        }
        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }
        if ($this->timeout !== null) {
            $data['timeout'] = $this->timeout;
        }
        if ($this->scheduledAt !== null) {
            $data['scheduled_at'] = $this->scheduledAt;
        }
        if ($this->expiresAt !== null) {
            $data['expires_at'] = $this->expiresAt;
        }
        if ($this->createdAt !== null) {
            $data['created_at'] = $this->createdAt;
        }
        if ($this->enqueuedAt !== null) {
            $data['enqueued_at'] = $this->enqueuedAt;
        }
        if ($this->startedAt !== null) {
            $data['started_at'] = $this->startedAt;
        }
        if ($this->completedAt !== null) {
            $data['completed_at'] = $this->completedAt;
        }
        if ($this->error !== null) {
            $data['error'] = $this->error;
        }
        if ($this->result !== null) {
            $data['result'] = $this->result;
        }
        if ($this->retryPolicy !== null) {
            $data['retry'] = $this->retryPolicy->toArray();
        }
        if ($this->uniquePolicy !== null) {
            $data['unique'] = $this->uniquePolicy->toArray();
        }
        if ($this->schema !== null) {
            $data['schema'] = $this->schema;
        }
        if ($this->progress !== null) {
            $data['progress'] = $this->progress;
        }
        return $data;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            type: $data['type'] ?? '',
            args: $data['args'] ?? [],
            queue: $data['queue'] ?? 'default',
            state: $data['state'] ?? self::STATE_AVAILABLE,
            attempt: $data['attempt'] ?? 0,
            priority: $data['priority'] ?? 0,
            meta: $data['meta'] ?? [],
            timeout: $data['timeout'] ?? null,
            scheduledAt: $data['scheduled_at'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            createdAt: $data['created_at'] ?? null,
            enqueuedAt: $data['enqueued_at'] ?? null,
            startedAt: $data['started_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            error: is_array($data['error'] ?? null) ? $data['error'] : (
                is_string($data['error'] ?? null) ? ['message' => $data['error']] : null
            ),
            result: $data['result'] ?? null,
            retryPolicy: isset($data['retry']) || isset($data['retry_policy'])
                ? RetryPolicy::fromArray($data['retry'] ?? $data['retry_policy'])
                : null,
            uniquePolicy: isset($data['unique']) ? UniquePolicy::fromArray($data['unique']) : null,
            schema: $data['schema'] ?? null,
            progress: isset($data['progress']) ? (float) $data['progress'] : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace OpenJobSpec\Testing;

/**
 * Mutable job representation for the fake transport.
 */
class FakeJob
{
    public string $state = 'available';
    public int $attempt = 0;
    public mixed $result = null;
    public ?array $error = null;
    public string $createdAt;

    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $args,
        public readonly string $queue = 'default',
        public readonly int $priority = 0,
        public readonly array $meta = [],
        public readonly ?string $scheduledAt = null,
        public readonly ?array $retry = null,
        public readonly ?array $unique = null,
        public readonly ?string $schema = null,
        public readonly ?int $timeout = null,
    ) {
        $this->createdAt = date('c');
        $this->state = $scheduledAt !== null ? 'scheduled' : 'available';
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
            'priority' => $this->priority,
            'created_at' => $this->createdAt,
        ];
        if ($this->meta !== []) {
            $data['meta'] = $this->meta;
        }
        if ($this->scheduledAt !== null) {
            $data['scheduled_at'] = $this->scheduledAt;
        }
        if ($this->error !== null) {
            $data['error'] = $this->error;
        }
        if ($this->result !== null) {
            $data['result'] = $this->result;
        }
        if ($this->retry !== null) {
            $data['retry'] = $this->retry;
        }
        if ($this->unique !== null) {
            $data['unique'] = $this->unique;
        }
        if ($this->schema !== null) {
            $data['schema'] = $this->schema;
        }
        if ($this->timeout !== null) {
            $data['timeout'] = $this->timeout;
        }
        return $data;
    }
}

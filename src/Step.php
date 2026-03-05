<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Workflow step definition for chain/group/batch primitives.
 */
class Step
{
    public function __construct(
        public readonly string $type,
        public readonly array $args = [],
        public readonly string $queue = 'default',
        public readonly ?int $priority = null,
        public readonly ?RetryPolicy $retryPolicy = null,
        public readonly ?int $timeout = null,
        public readonly array $meta = [],
    ) {}

    public function toArray(): array
    {
        $result = [
            'type' => $this->type,
            'args' => $this->args,
        ];
        if ($this->queue !== 'default') {
            $result['queue'] = $this->queue;
        }
        if ($this->priority !== null) {
            $result['priority'] = $this->priority;
        }
        if ($this->retryPolicy !== null) {
            $result['retry'] = $this->retryPolicy->toArray();
        }
        if ($this->timeout !== null) {
            $result['timeout'] = $this->timeout;
        }
        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }
        return $result;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['type'] ?? '',
            args: $data['args'] ?? [],
            queue: $data['queue'] ?? 'default',
            priority: $data['priority'] ?? null,
            retryPolicy: isset($data['retry']) ? RetryPolicy::fromArray($data['retry']) : null,
            timeout: $data['timeout'] ?? null,
            meta: $data['meta'] ?? [],
        );
    }
}

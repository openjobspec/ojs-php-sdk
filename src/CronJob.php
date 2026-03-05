<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Cron job configuration for recurring jobs.
 */
class CronJob
{
    public function __construct(
        public readonly string $name,
        public readonly string $cron,
        public readonly string $type,
        public readonly array $args = [],
        public readonly string $queue = 'default',
        public readonly array $meta = [],
        public readonly ?RetryPolicy $retryPolicy = null,
        public readonly ?int $priority = null,
        public readonly ?int $timeout = null,
    ) {}

    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'cron' => $this->cron,
            'type' => $this->type,
            'args' => $this->args,
            'queue' => $this->queue,
        ];
        if ($this->meta !== []) {
            $result['meta'] = $this->meta;
        }
        if ($this->retryPolicy !== null) {
            $result['retry'] = $this->retryPolicy->toArray();
        }
        if ($this->priority !== null) {
            $result['priority'] = $this->priority;
        }
        if ($this->timeout !== null) {
            $result['timeout'] = $this->timeout;
        }
        return $result;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            cron: $data['cron'] ?? '',
            type: $data['type'] ?? '',
            args: $data['args'] ?? [],
            queue: $data['queue'] ?? 'default',
            meta: $data['meta'] ?? [],
            retryPolicy: isset($data['retry']) ? RetryPolicy::fromArray($data['retry']) : null,
            priority: $data['priority'] ?? null,
            timeout: $data['timeout'] ?? null,
        );
    }
}

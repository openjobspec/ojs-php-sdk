<?php

declare(strict_types=1);

namespace OpenJobSpec;

class Job
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $args,
        public readonly string $queue,
        public readonly string $state,
        public readonly int $attempt = 0,
        public readonly ?string $createdAt = null,
        public readonly ?string $completedAt = null,
        public readonly ?string $error = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            type: $data['type'] ?? '',
            args: $data['args'] ?? [],
            queue: $data['queue'] ?? 'default',
            state: $data['state'] ?? 'available',
            attempt: $data['attempt'] ?? 0,
            createdAt: $data['created_at'] ?? null,
            completedAt: $data['completed_at'] ?? null,
            error: $data['error'] ?? null,
        );
    }
}

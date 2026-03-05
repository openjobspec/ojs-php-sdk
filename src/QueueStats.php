<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Queue statistics returned by the admin API.
 */
class QueueStats
{
    public function __construct(
        public readonly string $name,
        public readonly int $available = 0,
        public readonly int $active = 0,
        public readonly int $completed = 0,
        public readonly int $retryable = 0,
        public readonly int $discarded = 0,
        public readonly int $scheduled = 0,
        public readonly int $cancelled = 0,
        public readonly bool $paused = false,
    ) {}

    public function total(): int
    {
        return $this->available + $this->active + $this->completed
            + $this->retryable + $this->discarded + $this->scheduled + $this->cancelled;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            available: $data['available'] ?? 0,
            active: $data['active'] ?? 0,
            completed: $data['completed'] ?? 0,
            retryable: $data['retryable'] ?? 0,
            discarded: $data['discarded'] ?? 0,
            scheduled: $data['scheduled'] ?? 0,
            cancelled: $data['cancelled'] ?? 0,
            paused: $data['paused'] ?? false,
        );
    }
}

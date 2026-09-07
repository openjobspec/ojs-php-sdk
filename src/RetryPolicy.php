<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Retry policy configuration for jobs.
 */
class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int|string $initialInterval = 'PT1S',
        public readonly float $backoffCoefficient = 2.0,
        public readonly int|string $maxInterval = 'PT5M',
        public readonly bool $jitter = true,
        public readonly array $nonRetryableErrors = [],
    ) {
        if ($this->maxAttempts < 0) {
            throw new ValidationError('maxAttempts must be >= 0');
        }
        if ($this->backoffCoefficient < 1.0) {
            throw new ValidationError('backoffCoefficient must be >= 1.0');
        }
    }

    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'initial_interval' => $this->initialInterval,
            'backoff_coefficient' => $this->backoffCoefficient,
            'max_interval' => $this->maxInterval,
            'jitter' => $this->jitter,
            'non_retryable_errors' => $this->nonRetryableErrors,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            maxAttempts: $data['max_attempts'] ?? 3,
            initialInterval: $data['initial_interval'] ?? 'PT1S',
            backoffCoefficient: (float) ($data['backoff_coefficient'] ?? 2.0),
            maxInterval: $data['max_interval'] ?? 'PT5M',
            jitter: $data['jitter'] ?? true,
            nonRetryableErrors: $data['non_retryable_errors'] ?? [],
        );
    }

    /**
     * Parse a duration string (ISO 8601 or shorthand) to seconds.
     */
    public static function parseDuration(string $duration): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(s|m|h|d)$/i', $duration, $m)) {
            $value = (float) $m[1];
            return match (strtolower($m[2])) {
                's' => $value,
                'm' => $value * 60,
                'h' => $value * 3600,
                'd' => $value * 86400,
                default => $value,
            };
        }

        try {
            $interval = new \DateInterval($duration);
            return ($interval->days * 86400)
                + ($interval->h * 3600)
                + ($interval->i * 60)
                + $interval->s;
        } catch (\Exception) {
            throw new ValidationError("Invalid duration format: {$duration}");
        }
    }
}

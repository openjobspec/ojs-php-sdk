<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Unique job policy for deduplication.
 */
class UniquePolicy
{
    public function __construct(
        public readonly array $keys = ['type'],
        public readonly array $argsKeys = [],
        public readonly array $metaKeys = [],
        public readonly int|string|null $period = null,
        public readonly array $states = ['available', 'active', 'scheduled', 'retryable', 'pending'],
        public readonly string $onConflict = 'reject',
    ) {
        $validConflicts = ['reject', 'replace', 'replace_except_schedule', 'ignore'];
        if (!in_array($this->onConflict, $validConflicts, true)) {
            throw new ValidationError(
                "Invalid on_conflict value: {$this->onConflict}. Must be one of: " . implode(', ', $validConflicts)
            );
        }
    }

    public function toArray(): array
    {
        $result = [
            'keys' => $this->keys,
            'on_conflict' => $this->onConflict,
        ];
        if ($this->argsKeys !== []) {
            $result['args_keys'] = $this->argsKeys;
        }
        if ($this->metaKeys !== []) {
            $result['meta_keys'] = $this->metaKeys;
        }
        if ($this->period !== null) {
            $result['period'] = $this->period;
        }
        if ($this->states !== ['available', 'active', 'scheduled', 'retryable', 'pending']) {
            $result['states'] = $this->states;
        }
        return $result;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            keys: $data['keys'] ?? ['type'],
            argsKeys: $data['args_keys'] ?? [],
            metaKeys: $data['meta_keys'] ?? [],
            period: $data['period'] ?? null,
            states: $data['states'] ?? ['available', 'active', 'scheduled', 'retryable', 'pending'],
            onConflict: $data['on_conflict'] ?? 'reject',
        );
    }
}

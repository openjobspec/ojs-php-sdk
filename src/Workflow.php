<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Workflow builder for OJS chain/group/batch primitives.
 */
class Workflow
{
    public static function chain(string $name, array $steps): array
    {
        return [
            'type' => 'chain',
            'name' => $name,
            'steps' => array_map(fn($s) => self::normalizeStep($s), $steps),
        ];
    }

    public static function group(string $name, array $jobs): array
    {
        return [
            'type' => 'group',
            'name' => $name,
            'jobs' => array_map(fn($j) => self::normalizeStep($j), $jobs),
        ];
    }

    public static function batch(string $name, array $jobs, array $callbacks = []): array
    {
        return [
            'type' => 'batch',
            'name' => $name,
            'jobs' => array_map(fn($j) => self::normalizeStep($j), $jobs),
            'callbacks' => $callbacks,
        ];
    }

    private static function normalizeStep(array $step): array
    {
        return [
            'type' => $step['type'] ?? $step[0] ?? '',
            'args' => $step['args'] ?? $step[1] ?? [],
        ];
    }
}

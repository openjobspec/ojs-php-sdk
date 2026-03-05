<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Workflow builder for OJS chain/group/batch primitives.
 *
 * Workflows are submitted via Client::workflow() and orchestrated server-side.
 */
class Workflow
{
    /**
     * Create a chain (sequential) workflow.
     *
     * Jobs execute in order; each starts after the previous completes.
     *
     * @param string $name Workflow name
     * @param array<Step|array> $steps Ordered list of steps
     */
    public static function chain(string $name, array $steps): array
    {
        return [
            'type' => 'chain',
            'name' => $name,
            'steps' => array_map(fn($s) => self::normalizeStep($s), $steps),
        ];
    }

    /**
     * Create a group (parallel) workflow.
     *
     * All jobs execute concurrently; the group completes when all finish.
     *
     * @param string $name Workflow name
     * @param array<Step|array> $jobs Jobs to run in parallel
     */
    public static function group(string $name, array $jobs): array
    {
        return [
            'type' => 'group',
            'name' => $name,
            'jobs' => array_map(fn($j) => self::normalizeStep($j), $jobs),
        ];
    }

    /**
     * Create a batch workflow with optional callbacks.
     *
     * Jobs execute concurrently with optional on_complete/on_success/on_failure callbacks.
     *
     * @param string $name Workflow name
     * @param array<Step|array> $jobs Jobs to run in parallel
     * @param Step|array|null $onComplete Callback when all jobs finish (success or failure)
     * @param Step|array|null $onSuccess Callback when all jobs succeed
     * @param Step|array|null $onFailure Callback when any job fails
     */
    public static function batch(
        string $name,
        array $jobs,
        Step|array|null $onComplete = null,
        Step|array|null $onSuccess = null,
        Step|array|null $onFailure = null,
    ): array {
        $result = [
            'type' => 'batch',
            'name' => $name,
            'jobs' => array_map(fn($j) => self::normalizeStep($j), $jobs),
        ];

        $callbacks = [];
        if ($onComplete !== null) {
            $callbacks['on_complete'] = self::normalizeStep($onComplete);
        }
        if ($onSuccess !== null) {
            $callbacks['on_success'] = self::normalizeStep($onSuccess);
        }
        if ($onFailure !== null) {
            $callbacks['on_failure'] = self::normalizeStep($onFailure);
        }
        if ($callbacks !== []) {
            $result['callbacks'] = $callbacks;
        }

        return $result;
    }

    private static function normalizeStep(Step|array $step): array
    {
        if ($step instanceof Step) {
            return $step->toArray();
        }

        return [
            'type' => $step['type'] ?? $step[0] ?? '',
            'args' => $step['args'] ?? $step[1] ?? [],
            ...array_diff_key($step, array_flip(['type', 'args', 0, 1])),
        ];
    }
}

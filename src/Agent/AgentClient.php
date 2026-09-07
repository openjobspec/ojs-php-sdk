<?php

declare(strict_types=1);

namespace OpenJobSpec\Agent;

/**
 * Controls how conflicting turns are reconciled during a merge.
 */
final class MergeStrategy
{
    public const OURS = 'ours';
    public const THEIRS = 'theirs';
    public const UNION = 'union';
}

/**
 * Configures where a new branch diverges from the main execution.
 */
final class ForkOptions
{
    public function __construct(
        public readonly int $atTurn,
        public readonly string $branchName,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['at_turn' => $this->atTurn, 'branch_name' => $this->branchName];
    }
}

/**
 * Identifiers produced by a successful fork.
 */
final class ForkResult
{
    public function __construct(
        public readonly string $branchId,
        public readonly string $contentId,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['branch_id'] ?? '', $data['content_id'] ?? '');
    }
}

/**
 * Specifies the two branches to merge and the strategy to use.
 */
final class MergeOptions
{
    public function __construct(
        public readonly string $branchA,
        public readonly string $branchB,
        public readonly string $strategy = MergeStrategy::OURS,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'branch_a' => $this->branchA,
            'branch_b' => $this->branchB,
            'strategy' => $this->strategy,
        ];
    }
}

/**
 * Outcome of a merge operation.
 */
final class MergeResult
{
    /** @param string[] $conflicts */
    public function __construct(
        public readonly string $mergedId,
        public readonly array $conflicts = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['merged_id'] ?? '', $data['conflicts'] ?? []);
    }
}

/**
 * Human reviewer's verdict for a paused agent.
 */
final class ResumeDecision
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly bool $approved,
        public readonly string $comment = '',
        public readonly array $metadata = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'comment' => $this->comment,
            'metadata' => $this->metadata,
        ];
    }
}

/**
 * Configures a deterministic replay of a previous execution.
 */
final class ReplayOptions
{
    /** @param array<string, string> $mockProviders */
    public function __construct(
        public readonly int $fromTurn,
        public readonly array $mockProviders = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['from_turn' => $this->fromTurn, 'mock_providers' => $this->mockProviders];
    }
}

/**
 * Summarises a completed replay run.
 */
final class ReplayResult
{
    /** @param Divergence[] $divergences */
    public function __construct(
        public readonly int $steps,
        public readonly array $divergences = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $divergences = array_map(
            fn(array $d) => new Divergence($d['turn'] ?? 0, $d['expected'] ?? '', $d['actual'] ?? ''),
            $data['divergences'] ?? []
        );
        return new self($data['steps'] ?? 0, $divergences);
    }
}

/**
 * A single point where a replay differed from the original execution.
 */
final class Divergence
{
    public function __construct(
        public readonly int $turn,
        public readonly string $expected,
        public readonly string $actual,
    ) {}
}

/**
 * Thin HTTP client for the OJS Agent API providing fork/merge branching,
 * pause/resume human-in-the-loop control, and deterministic replay.
 */
class AgentClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        if (empty($baseUrl)) {
            throw new \InvalidArgumentException('base URL must not be empty');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Creates a new execution branch diverging at the specified turn.
     */
    public function fork(string $jobId, ForkOptions $options): ForkResult
    {
        $data = $this->postJson("/v1/agent/jobs/{$jobId}/fork", $options->toArray());
        return ForkResult::fromArray($data);
    }

    /**
     * Combines two branches using the specified merge strategy.
     */
    public function merge(string $jobId, MergeOptions $options): MergeResult
    {
        $data = $this->postJson("/v1/agent/jobs/{$jobId}/merge", $options->toArray());
        return MergeResult::fromArray($data);
    }

    /**
     * Requests the agent stop execution after the current turn.
     */
    public function pause(string $jobId, string $reason): void
    {
        $this->postJson("/v1/agent/jobs/{$jobId}/pause", ['reason' => $reason]);
    }

    /**
     * Instructs a paused agent to continue or abort.
     */
    public function resume(string $jobId, ResumeDecision $decision): void
    {
        $this->postJson("/v1/agent/jobs/{$jobId}/resume", $decision->toArray());
    }

    /**
     * Re-executes the job deterministically from the specified turn.
     */
    public function replay(string $jobId, ReplayOptions $options): ReplayResult
    {
        $data = $this->postJson("/v1/agent/jobs/{$jobId}/replay", $options->toArray());
        return ReplayResult::fromArray($data);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $url = $this->baseUrl . $path;
        $payload = json_encode($body, JSON_THROW_ON_ERROR);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $payload,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \RuntimeException("request to {$url} failed");
        }

        /** @var string[] $legacyResponseHeaders */
        $legacyResponseHeaders = get_defined_vars()['http_response_header'];
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : $legacyResponseHeaders;
        $statusCode = $this->extractStatusCode($responseHeaders);

        if ($statusCode >= 200 && $statusCode < 300) {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR) ?: [];
        }

        match ($statusCode) {
            404 => throw new \RuntimeException('agent not found'),
            409 => throw new \RuntimeException('branch conflict'),
            422 => throw new \RuntimeException('agent is not paused'),
            default => throw new \RuntimeException("unexpected status {$statusCode}"),
        };
    }

    /**
     * @param string[] $headers
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 500;
    }
}

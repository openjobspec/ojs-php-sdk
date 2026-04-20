<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\RetryPolicy;
use OpenJobSpec\UniquePolicy;
use OpenJobSpec\Job;
use OpenJobSpec\OjsException;
use OpenJobSpec\NotFoundError;
use OpenJobSpec\ConflictError;
use OpenJobSpec\RateLimitError;
use OpenJobSpec\ServerError;
use OpenJobSpec\ValidationError;
use OpenJobSpec\ConnectionError;
use OpenJobSpec\TimeoutError;
use PHPUnit\Framework\TestCase;

/**
 * Extended tests for error hierarchy, retry policy edge cases,
 * unique policy validation, and job model serialization.
 */
final class ExtendedCoreTest extends TestCase
{
    // ── Error Hierarchy ──────────────────────────────────

    public function testAllErrorTypesExtendOjsException(): void
    {
        $errors = [
            new NotFoundError('not found'),
            new ConflictError('conflict'),
            new RateLimitError('rate limited'),
            new ServerError('server error', 500),
            new ValidationError('validation', 400),
            new ConnectionError('connection'),
            new TimeoutError('timeout'),
        ];

        foreach ($errors as $error) {
            $this->assertInstanceOf(OjsException::class, $error);
        }
    }

    public function testNotFoundErrorIsNotRetryable(): void
    {
        $error = new NotFoundError('Job not found');
        $this->assertFalse($error->isRetryable());
    }

    public function testConflictErrorIsNotRetryable(): void
    {
        $error = new ConflictError('Duplicate job');
        $this->assertFalse($error->isRetryable());
    }

    public function testRateLimitErrorIsRetryable(): void
    {
        $error = new RateLimitError('Too many requests');
        $this->assertTrue($error->isRetryable());
    }

    public function testServerErrorIsRetryable(): void
    {
        $error = new ServerError('Internal error', 500);
        $this->assertTrue($error->isRetryable());
    }

    public function testServerError503IsRetryable(): void
    {
        $error = new ServerError('Service unavailable', 503);
        $this->assertTrue($error->isRetryable());
    }

    // ── Retry Policy ─────────────────────────────────────

    public function testRetryPolicyDefaults(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3);
        $this->assertSame(3, $policy->maxAttempts);
    }

    public function testRetryPolicyMaxAttemptsZeroMeansNoRetry(): void
    {
        $policy = new RetryPolicy(maxAttempts: 0);
        $this->assertSame(0, $policy->maxAttempts);
        $this->assertSame(0, $policy->toArray()['max_attempts']);

        $restored = RetryPolicy::fromArray($policy->toArray());
        $this->assertSame(0, $restored->maxAttempts);
    }

    public function testRetryPolicyToArray(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 5,
            initialInterval: 2,
            backoffCoefficient: 2.0,
            maxInterval: 60,
        );

        $arr = $policy->toArray();
        $this->assertSame(5, $arr['max_attempts']);
        $this->assertSame(2, $arr['initial_interval']);
        $this->assertSame(2.0, $arr['backoff_coefficient']);
        $this->assertSame(60, $arr['max_interval']);
    }

    public function testRetryPolicyFromArray(): void
    {
        $policy = RetryPolicy::fromArray([
            'max_attempts' => 10,
            'initial_interval' => 5,
            'backoff_coefficient' => 1.5,
            'max_interval' => 120,
        ]);

        $this->assertSame(10, $policy->maxAttempts);
        $this->assertSame(5, $policy->initialInterval);
        $this->assertSame(1.5, $policy->backoffCoefficient);
        $this->assertSame(120, $policy->maxInterval);
    }

    public function testRetryPolicyFromArrayWithMinimalFields(): void
    {
        $policy = RetryPolicy::fromArray([
            'max_attempts' => 3,
        ]);

        $this->assertSame(3, $policy->maxAttempts);
    }

    public function testRetryPolicyFromArrayPreservesNoRetry(): void
    {
        $policy = RetryPolicy::fromArray(['max_attempts' => 0]);

        $this->assertSame(0, $policy->maxAttempts);
        $this->assertSame(0, $policy->toArray()['max_attempts']);
    }

    // ── Unique Policy ────────────────────────────────────

    public function testUniquePolicyToArray(): void
    {
        $policy = new UniquePolicy(
            period: 300,
            states: ['available', 'active'],
            onConflict: 'reject',
        );

        $arr = $policy->toArray();
        $this->assertSame(300, $arr['period']);
        $this->assertSame(['available', 'active'], $arr['states']);
        $this->assertSame('reject', $arr['on_conflict']);
    }

    public function testUniquePolicyFromArray(): void
    {
        $policy = UniquePolicy::fromArray([
            'period' => 600,
            'states' => ['available'],
            'on_conflict' => 'replace',
        ]);

        $this->assertSame(600, $policy->period);
        $this->assertSame(['available'], $policy->states);
        $this->assertSame('replace', $policy->onConflict);
    }

    // ── Job Model ────────────────────────────────────────

    public function testJobFromArrayCreatesValidJob(): void
    {
        $data = [
            'id' => '019461a8-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            'type' => 'test.job',
            'queue' => 'default',
            'args' => [['key' => 'value']],
            'state' => 'available',
            'attempt' => 0,
            'priority' => 0,
            'created_at' => '2026-03-05T10:00:00Z',
        ];

        $job = Job::fromArray($data);

        $this->assertSame('019461a8-1a2b-7c3d-8e4f-5a6b7c8d9e0f', $job->id);
        $this->assertSame('test.job', $job->type);
        $this->assertSame('default', $job->queue);
        $this->assertSame('available', $job->state);
        $this->assertSame(0, $job->attempt);
    }

    public function testJobFromArrayWithMeta(): void
    {
        $data = [
            'id' => '019461a8-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            'type' => 'test.meta',
            'queue' => 'default',
            'args' => [],
            'state' => 'available',
            'attempt' => 0,
            'meta' => ['source' => 'api', 'version' => '1.0'],
        ];

        $job = Job::fromArray($data);
        $this->assertSame(['source' => 'api', 'version' => '1.0'], $job->meta);
    }

    public function testJobFromArrayWithRetryPolicy(): void
    {
        $data = [
            'id' => '019461a8-1a2b-7c3d-8e4f-5a6b7c8d9e0f',
            'type' => 'test.retry',
            'queue' => 'default',
            'args' => [],
            'state' => 'available',
            'attempt' => 0,
            'retry_policy' => [
                'max_attempts' => 5,
                'initial_interval' => 1,
                'backoff_coefficient' => 2.0,
            ],
        ];

        $job = Job::fromArray($data);
        $this->assertNotNull($job->retryPolicy);
        $this->assertSame(5, $job->retryPolicy->maxAttempts);
    }

    public function testJobTerminalStateCheck(): void
    {
        $completedJob = Job::fromArray([
            'id' => '019461a8-0000-7000-8000-000000000001',
            'type' => 'test.terminal',
            'queue' => 'default',
            'args' => [],
            'state' => 'completed',
            'attempt' => 1,
        ]);

        $activeJob = Job::fromArray([
            'id' => '019461a8-0000-7000-8000-000000000002',
            'type' => 'test.active',
            'queue' => 'default',
            'args' => [],
            'state' => 'active',
            'attempt' => 1,
        ]);

        $terminalStates = ['completed', 'cancelled', 'discarded'];
        $this->assertContains($completedJob->state, $terminalStates);
        $this->assertNotContains($activeJob->state, $terminalStates);
    }
}

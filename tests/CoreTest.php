<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{
    RetryPolicy, UniquePolicy, CronJob, Step, Event, QueueStats,
    OjsException, ValidationError, NotFoundError, ConflictError,
    RateLimitError, ServerError, PayloadTooLargeError, TimeoutError, ConnectionError,
};
use PHPUnit\Framework\TestCase;

class CoreTest extends TestCase
{
    // ── RetryPolicy ─────────────────────────────────────────

    public function testRetryPolicyDefaults(): void
    {
        $policy = new RetryPolicy();
        $this->assertEquals(3, $policy->maxAttempts);
        $this->assertEquals('PT1S', $policy->initialInterval);
        $this->assertEquals(2.0, $policy->backoffCoefficient);
        $this->assertEquals('PT5M', $policy->maxInterval);
        $this->assertTrue($policy->jitter);
        $this->assertEquals([], $policy->nonRetryableErrors);
    }

    public function testRetryPolicyCustom(): void
    {
        $policy = new RetryPolicy(
            maxAttempts: 10,
            initialInterval: '5s',
            backoffCoefficient: 3.0,
            maxInterval: '1h',
            jitter: false,
            nonRetryableErrors: ['InvalidArgument'],
        );

        $arr = $policy->toArray();
        $this->assertEquals(10, $arr['max_attempts']);
        $this->assertEquals('5s', $arr['initial_interval']);
        $this->assertEquals(3.0, $arr['backoff_coefficient']);
        $this->assertFalse($arr['jitter']);
        $this->assertEquals(['InvalidArgument'], $arr['non_retryable_errors']);
    }

    public function testRetryPolicyFromArray(): void
    {
        $policy = RetryPolicy::fromArray([
            'max_attempts' => 5,
            'initial_interval' => 'PT30S',
            'backoff_coefficient' => 1.5,
        ]);

        $this->assertEquals(5, $policy->maxAttempts);
        $this->assertEquals('PT30S', $policy->initialInterval);
    }

    public function testRetryPolicyInvalidMaxAttempts(): void
    {
        $this->expectException(ValidationError::class);
        new RetryPolicy(maxAttempts: 0);
    }

    public function testRetryPolicyInvalidBackoff(): void
    {
        $this->expectException(ValidationError::class);
        new RetryPolicy(backoffCoefficient: 0.5);
    }

    public function testParseDurationShorthand(): void
    {
        $this->assertEquals(30.0, RetryPolicy::parseDuration('30s'));
        $this->assertEquals(300.0, RetryPolicy::parseDuration('5m'));
        $this->assertEquals(7200.0, RetryPolicy::parseDuration('2h'));
        $this->assertEquals(86400.0, RetryPolicy::parseDuration('1d'));
    }

    public function testParseDurationISO8601(): void
    {
        $this->assertEquals(1.0, RetryPolicy::parseDuration('PT1S'));
        $this->assertEquals(300.0, RetryPolicy::parseDuration('PT5M'));
        $this->assertEquals(3600.0, RetryPolicy::parseDuration('PT1H'));
    }

    public function testParseDurationInvalid(): void
    {
        $this->expectException(ValidationError::class);
        RetryPolicy::parseDuration('invalid');
    }

    // ── UniquePolicy ────────────────────────────────────────

    public function testUniquePolicyDefaults(): void
    {
        $policy = new UniquePolicy();
        $this->assertEquals(['type'], $policy->keys);
        $this->assertEquals('reject', $policy->onConflict);
    }

    public function testUniquePolicyCustom(): void
    {
        $policy = new UniquePolicy(
            keys: ['type', 'queue'],
            argsKeys: ['user_id'],
            period: 'PT1H',
            onConflict: 'replace',
        );

        $arr = $policy->toArray();
        $this->assertEquals(['type', 'queue'], $arr['keys']);
        $this->assertEquals(['user_id'], $arr['args_keys']);
        $this->assertEquals('PT1H', $arr['period']);
        $this->assertEquals('replace', $arr['on_conflict']);
    }

    public function testUniquePolicyInvalidConflict(): void
    {
        $this->expectException(ValidationError::class);
        new UniquePolicy(onConflict: 'invalid');
    }

    public function testUniquePolicyFromArray(): void
    {
        $policy = UniquePolicy::fromArray([
            'keys' => ['type', 'args'],
            'on_conflict' => 'ignore',
            'period' => '1h',
        ]);
        $this->assertEquals(['type', 'args'], $policy->keys);
        $this->assertEquals('ignore', $policy->onConflict);
    }

    // ── CronJob ─────────────────────────────────────────────

    public function testCronJob(): void
    {
        $cron = new CronJob(
            name: 'daily-cleanup',
            cron: '0 3 * * *',
            type: 'maintenance.cleanup',
            args: ['full'],
            queue: 'maintenance',
            priority: 5,
        );

        $arr = $cron->toArray();
        $this->assertEquals('daily-cleanup', $arr['name']);
        $this->assertEquals('0 3 * * *', $arr['cron']);
        $this->assertEquals('maintenance.cleanup', $arr['type']);
        $this->assertEquals(5, $arr['priority']);
    }

    public function testCronJobFromArray(): void
    {
        $cron = CronJob::fromArray([
            'name' => 'hourly-sync',
            'cron' => '0 * * * *',
            'type' => 'sync.run',
        ]);
        $this->assertEquals('hourly-sync', $cron->name);
        $this->assertEquals('default', $cron->queue);
    }

    // ── Step ────────────────────────────────────────────────

    public function testStep(): void
    {
        $step = new Step(
            type: 'data.process',
            args: ['format' => 'csv'],
            queue: 'processing',
            priority: 10,
            timeout: 300,
        );

        $arr = $step->toArray();
        $this->assertEquals('data.process', $arr['type']);
        $this->assertEquals('processing', $arr['queue']);
        $this->assertEquals(10, $arr['priority']);
        $this->assertEquals(300, $arr['timeout']);
    }

    public function testStepDefaultQueue(): void
    {
        $step = new Step(type: 'test', args: []);
        $arr = $step->toArray();
        $this->assertArrayNotHasKey('queue', $arr);
    }

    // ── Event ───────────────────────────────────────────────

    public function testEventConstants(): void
    {
        $this->assertEquals('job.enqueued', Event::JOB_ENQUEUED);
        $this->assertEquals('job.completed', Event::JOB_COMPLETED);
        $this->assertEquals('workflow.started', Event::WORKFLOW_STARTED);
        $this->assertEquals('cron.triggered', Event::CRON_TRIGGERED);
    }

    public function testEventFromArray(): void
    {
        $event = Event::fromArray([
            'type' => 'job.completed',
            'data' => ['job_id' => 'j1'],
            'id' => 'e1',
            'time' => '2024-01-01T00:00:00Z',
        ]);

        $this->assertEquals('job.completed', $event->type);
        $this->assertEquals(['job_id' => 'j1'], $event->data);
        $this->assertEquals('e1', $event->id);
    }

    public function testEventToArray(): void
    {
        $event = new Event(type: 'test', data: ['k' => 'v'], id: 'e1');
        $arr = $event->toArray();

        $this->assertEquals('test', $arr['type']);
        $this->assertEquals(['k' => 'v'], $arr['data']);
        $this->assertEquals('e1', $arr['id']);
    }

    // ── QueueStats ──────────────────────────────────────────

    public function testQueueStats(): void
    {
        $stats = QueueStats::fromArray([
            'name' => 'default',
            'available' => 10,
            'active' => 3,
            'completed' => 100,
            'discarded' => 2,
            'paused' => false,
        ]);

        $this->assertEquals('default', $stats->name);
        $this->assertEquals(10, $stats->available);
        $this->assertEquals(3, $stats->active);
        $this->assertFalse($stats->paused);
        $this->assertGreaterThan(0, $stats->total());
    }

    // ── Error Hierarchy ─────────────────────────────────────

    public function testErrorFromResponse404(): void
    {
        $error = OjsException::fromResponse(['message' => 'Job not found'], 404);
        $this->assertInstanceOf(NotFoundError::class, $error);
        $this->assertFalse($error->retryable);
    }

    public function testErrorFromResponse409(): void
    {
        $error = OjsException::fromResponse(['message' => 'Duplicate'], 409);
        $this->assertInstanceOf(ConflictError::class, $error);
        $this->assertFalse($error->retryable);
    }

    public function testErrorFromResponse429(): void
    {
        $error = OjsException::fromResponse(['message' => 'Too many requests', 'retry_after' => 30], 429);
        $this->assertInstanceOf(RateLimitError::class, $error);
        $this->assertTrue($error->retryable);
        $this->assertEquals(30, $error->retryAfter);
    }

    public function testErrorFromResponse413(): void
    {
        $error = OjsException::fromResponse(['message' => 'Payload too large'], 413);
        $this->assertInstanceOf(PayloadTooLargeError::class, $error);
        $this->assertFalse($error->retryable);
    }

    public function testErrorFromResponse500(): void
    {
        $error = OjsException::fromResponse(['message' => 'Internal error'], 500);
        $this->assertInstanceOf(ServerError::class, $error);
        $this->assertTrue($error->retryable);
    }

    public function testErrorFromResponse422(): void
    {
        $error = OjsException::fromResponse(['message' => 'Bad args'], 422);
        $this->assertInstanceOf(ValidationError::class, $error);
        $this->assertFalse($error->retryable);
    }

    public function testConnectionError(): void
    {
        $error = new ConnectionError('Network unreachable');
        $this->assertTrue($error->retryable);
        $this->assertEquals('connection_error', $error->code);
    }

    public function testTimeoutError(): void
    {
        $error = new TimeoutError('Request timed out');
        $this->assertTrue($error->retryable);
        $this->assertEquals('timeout', $error->code);
    }
}

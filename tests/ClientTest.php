<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Job;
use OpenJobSpec\Workflow;
use OpenJobSpec\Step;
use OpenJobSpec\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('http://fake', ['transport' => $this->transport]);
    }

    // ── Job Model ───────────────────────────────────────────

    public function testJobFromArrayMinimal(): void
    {
        $job = Job::fromArray([
            'id' => 'test-123',
            'type' => 'email.send',
            'args' => ['user@example.com'],
            'queue' => 'emails',
            'state' => 'available',
        ]);

        $this->assertEquals('test-123', $job->id);
        $this->assertEquals('email.send', $job->type);
        $this->assertEquals(['user@example.com'], $job->args);
        $this->assertEquals('emails', $job->queue);
        $this->assertEquals('available', $job->state);
        $this->assertEquals(0, $job->attempt);
        $this->assertEquals(0, $job->priority);
        $this->assertFalse($job->isTerminal());
    }

    public function testJobFromArrayFull(): void
    {
        $job = Job::fromArray([
            'id' => 'test-456',
            'type' => 'report.generate',
            'args' => [42, 'csv'],
            'queue' => 'reports',
            'state' => 'completed',
            'attempt' => 3,
            'priority' => 10,
            'meta' => ['user_id' => 'u123'],
            'timeout' => 300,
            'scheduled_at' => '2024-01-01T00:00:00Z',
            'created_at' => '2024-01-01T00:00:00Z',
            'completed_at' => '2024-01-01T00:05:00Z',
            'result' => ['url' => 'https://example.com/report.csv'],
            'retry' => ['max_attempts' => 5],
            'progress' => 1.0,
        ]);

        $this->assertEquals('test-456', $job->id);
        $this->assertEquals('completed', $job->state);
        $this->assertTrue($job->isTerminal());
        $this->assertEquals(10, $job->priority);
        $this->assertEquals(['user_id' => 'u123'], $job->meta);
        $this->assertEquals(300, $job->timeout);
        $this->assertNotNull($job->retryPolicy);
        $this->assertEquals(5, $job->retryPolicy->maxAttempts);
        $this->assertEquals(1.0, $job->progress);
    }

    public function testJobTerminalStates(): void
    {
        $this->assertTrue(Job::fromArray(['state' => 'completed'])->isTerminal());
        $this->assertTrue(Job::fromArray(['state' => 'cancelled'])->isTerminal());
        $this->assertTrue(Job::fromArray(['state' => 'discarded'])->isTerminal());
        $this->assertFalse(Job::fromArray(['state' => 'active'])->isTerminal());
        $this->assertFalse(Job::fromArray(['state' => 'available'])->isTerminal());
        $this->assertFalse(Job::fromArray(['state' => 'scheduled'])->isTerminal());
        $this->assertFalse(Job::fromArray(['state' => 'retryable'])->isTerminal());
    }

    public function testJobToArray(): void
    {
        $job = Job::fromArray([
            'id' => 'j1',
            'type' => 'test',
            'args' => [1],
            'queue' => 'default',
            'state' => 'available',
            'priority' => 5,
            'meta' => ['k' => 'v'],
        ]);

        $arr = $job->toArray();
        $this->assertEquals('j1', $arr['id']);
        $this->assertEquals('test', $arr['type']);
        $this->assertEquals(5, $arr['priority']);
        $this->assertEquals(['k' => 'v'], $arr['meta']);
    }

    // ── Enqueue ─────────────────────────────────────────────

    public function testEnqueueSingle(): void
    {
        $job = $this->client->enqueue('email.send', ['user@example.com']);

        $this->assertEquals('email.send', $job->type);
        $this->assertEquals(['user@example.com'], $job->args);
        $this->assertTrue($this->transport->enqueued('email.send'));
    }

    public function testEnqueueWithOptions(): void
    {
        $job = $this->client->enqueue('email.send', ['user@example.com'], [
            'queue' => 'emails',
            'priority' => 10,
            'timeout' => 60,
            'meta' => ['user_id' => 'u1'],
            'scheduled_at' => '2025-01-01T00:00:00Z',
        ]);

        $this->assertEquals('email.send', $job->type);
        $this->assertTrue($this->transport->enqueued('email.send'));
    }

    public function testEnqueueBatch(): void
    {
        $jobs = $this->client->enqueueBatch([
            ['type' => 'email.send', 'args' => ['a@b.com']],
            ['type' => 'email.send', 'args' => ['c@d.com']],
            ['type' => 'sms.send', 'args' => ['+1234567890']],
        ]);

        $this->assertCount(3, $jobs);
        $this->assertEquals(2, $this->transport->enqueuedCount('email.send'));
        $this->assertEquals(1, $this->transport->enqueuedCount('sms.send'));
    }

    // ── Job Operations ──────────────────────────────────────

    public function testGetJob(): void
    {
        $enqueued = $this->client->enqueue('test.job', [1, 2, 3]);
        $fetched = $this->client->getJob($enqueued->id);

        $this->assertEquals($enqueued->id, $fetched->id);
        $this->assertEquals('test.job', $fetched->type);
    }

    public function testCancelJob(): void
    {
        $enqueued = $this->client->enqueue('test.job', []);
        $cancelled = $this->client->cancel($enqueued->id);

        $this->assertEquals('cancelled', $cancelled->state);
    }

    // ── Queue Management ────────────────────────────────────

    public function testGetQueues(): void
    {
        $this->client->enqueue('test', [], ['queue' => 'alpha']);
        $queues = $this->client->getQueues();
        $this->assertContains('alpha', $queues);
    }

    public function testGetQueueStats(): void
    {
        $this->client->enqueue('test', [], ['queue' => 'stats-q']);
        $stats = $this->client->getQueueStats('stats-q');

        $this->assertEquals('stats-q', $stats->name);
        $this->assertGreaterThanOrEqual(0, $stats->available);
    }

    // ── Dead Letter ─────────────────────────────────────────

    public function testDeadLetterOperations(): void
    {
        $deadJobs = $this->client->getDeadLetterJobs();
        $this->assertSame([], $deadJobs);
    }

    // ── Cron Jobs ───────────────────────────────────────────

    public function testCronJobLifecycle(): void
    {
        $cron = new \OpenJobSpec\CronJob(
            name: 'daily-report',
            cron: '0 9 * * *',
            type: 'report.generate',
            args: ['daily'],
            queue: 'reports',
        );

        $this->client->registerCronJob($cron);
        $cronJobs = $this->client->listCronJobs();
        $this->assertCount(1, $cronJobs);
        $this->assertEquals('daily-report', $cronJobs[0]->name);

        $this->client->unregisterCronJob('daily-report');
        $cronJobs = $this->client->listCronJobs();
        $this->assertCount(0, $cronJobs);
    }

    // ── Workflows ───────────────────────────────────────────

    public function testWorkflowChain(): void
    {
        $workflow = Workflow::chain('test-chain', [
            ['type' => 'step.one', 'args' => [1]],
            ['type' => 'step.two', 'args' => [2]],
        ]);

        $this->assertEquals('chain', $workflow['type']);
        $this->assertEquals('test-chain', $workflow['name']);
        $this->assertCount(2, $workflow['steps']);
    }

    public function testWorkflowChainWithSteps(): void
    {
        $workflow = Workflow::chain('typed-chain', [
            new Step(type: 'data.fetch', args: ['url' => 'https://example.com']),
            new Step(type: 'data.transform', args: ['format' => 'csv'], priority: 10),
        ]);

        $this->assertEquals('chain', $workflow['type']);
        $this->assertCount(2, $workflow['steps']);
        $this->assertEquals('data.fetch', $workflow['steps'][0]['type']);
        $this->assertEquals(10, $workflow['steps'][1]['priority']);
    }

    public function testWorkflowGroup(): void
    {
        $workflow = Workflow::group('test-group', [
            ['type' => 'task.a', 'args' => []],
            ['type' => 'task.b', 'args' => []],
        ]);

        $this->assertEquals('group', $workflow['type']);
        $this->assertCount(2, $workflow['jobs']);
    }

    public function testWorkflowBatchWithCallbacks(): void
    {
        $workflow = Workflow::batch(
            'email-blast',
            [
                new Step(type: 'email.send', args: ['to' => 'a@b.com']),
                new Step(type: 'email.send', args: ['to' => 'c@d.com']),
            ],
            onComplete: new Step(type: 'batch.report', args: []),
            onFailure: new Step(type: 'batch.alert', args: []),
        );

        $this->assertEquals('batch', $workflow['type']);
        $this->assertCount(2, $workflow['jobs']);
        $this->assertArrayHasKey('callbacks', $workflow);
        $this->assertEquals('batch.report', $workflow['callbacks']['on_complete']['type']);
        $this->assertEquals('batch.alert', $workflow['callbacks']['on_failure']['type']);
    }

    public function testSubmitWorkflow(): void
    {
        $workflow = Workflow::chain('api-chain', [
            ['type' => 'step.one', 'args' => [1]],
        ]);

        $result = $this->client->workflow($workflow);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('active', $result['state']);
    }

    // ── Schema Registry ─────────────────────────────────────

    public function testSchemaRegistration(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'email' => ['type' => 'string'],
            ],
            'required' => ['email'],
        ];

        $this->client->registerSchema('urn:ojs:email.send:v1', 'email.send', 'v1', $schema);
        $schemas = $this->client->listSchemas();
        $this->assertArrayHasKey('schemas', $schemas);
    }

    // ── Health & Manifest ───────────────────────────────────

    public function testHealth(): void
    {
        $health = $this->client->health();
        $this->assertEquals('ok', $health['status']);
    }

    public function testManifest(): void
    {
        $manifest = $this->client->manifest();
        $this->assertEquals('1.0', $manifest['version']);
        $this->assertEquals(4, $manifest['level']);
    }

    // ── Transport Assertions ────────────────────────────────

    public function testClearTransport(): void
    {
        $this->client->enqueue('test', []);
        $this->assertEquals(1, $this->transport->enqueuedCount());

        $this->transport->clear();
        $this->assertEquals(0, $this->transport->enqueuedCount());
    }
}

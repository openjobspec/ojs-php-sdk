<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{Client, Job, JobContext, Worker};
use OpenJobSpec\Testing\{FakeTransport, OjsAssertions};
use PHPUnit\Framework\TestCase;

class TestingTest extends TestCase
{
    use OjsAssertions;

    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('http://fake', ['transport' => $this->transport]);
    }

    // ── FakeTransport Query Methods ─────────────────────────

    public function testEnqueuedQuery(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);
        $this->client->enqueue('email.send', ['c@d.com']);
        $this->client->enqueue('sms.send', ['+1234567890']);

        $this->assertTrue($this->transport->enqueued('email.send'));
        $this->assertTrue($this->transport->enqueued('sms.send'));
        $this->assertFalse($this->transport->enqueued('unknown.type'));
    }

    public function testEnqueuedWithArgs(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);

        $this->assertTrue($this->transport->enqueued('email.send', ['a@b.com']));
        $this->assertFalse($this->transport->enqueued('email.send', ['other@b.com']));
    }

    public function testEnqueuedWithQueue(): void
    {
        $this->client->enqueue('email.send', ['a@b.com'], ['queue' => 'emails']);

        $this->assertTrue($this->transport->enqueued('email.send', queue: 'emails'));
        $this->assertFalse($this->transport->enqueued('email.send', queue: 'default'));
    }

    public function testEnqueuedCount(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);
        $this->client->enqueue('email.send', ['c@d.com']);
        $this->client->enqueue('sms.send', ['+1']);

        $this->assertEquals(3, $this->transport->enqueuedCount());
        $this->assertEquals(2, $this->transport->enqueuedCount('email.send'));
        $this->assertEquals(1, $this->transport->enqueuedCount('sms.send'));
        $this->assertEquals(0, $this->transport->enqueuedCount('unknown'));
    }

    public function testAllEnqueued(): void
    {
        $this->client->enqueue('email.send', ['a@b.com'], ['queue' => 'emails']);
        $this->client->enqueue('email.send', ['c@d.com'], ['queue' => 'emails']);
        $this->client->enqueue('email.send', ['e@f.com'], ['queue' => 'priority']);

        $all = $this->transport->allEnqueued('email.send');
        $this->assertCount(3, $all);

        $emailQueue = $this->transport->allEnqueued('email.send', 'emails');
        $this->assertCount(2, $emailQueue);
    }

    // ── Drain ───────────────────────────────────────────────

    public function testDrain(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);
        $this->client->enqueue('email.send', ['c@d.com']);
        $this->client->enqueue('sms.send', ['+1']);

        $processed = $this->transport->drain([
            'email.send' => fn(JobContext $ctx) => 'sent',
            'sms.send' => fn(JobContext $ctx) => 'sent',
        ]);

        $this->assertEquals(3, $processed);
        $this->assertTrue($this->transport->completed('email.send'));
        $this->assertTrue($this->transport->completed('sms.send'));
    }

    public function testDrainWithFailure(): void
    {
        $this->client->enqueue('fail.job', []);

        $this->transport->drain([
            'fail.job' => function (JobContext $ctx) {
                throw new \RuntimeException('boom');
            },
        ]);

        $this->assertTrue($this->transport->failed('fail.job'));
    }

    public function testDrainMaxJobs(): void
    {
        $this->client->enqueue('test', []);
        $this->client->enqueue('test', []);
        $this->client->enqueue('test', []);

        $processed = $this->transport->drain(
            ['test' => fn(JobContext $ctx) => 'ok'],
            maxJobs: 2,
        );

        $this->assertEquals(2, $processed);
    }

    // ── Clear ───────────────────────────────────────────────

    public function testClear(): void
    {
        $this->client->enqueue('test', []);
        $this->assertEquals(1, $this->transport->enqueuedCount());

        $this->transport->clear();
        $this->assertEquals(0, $this->transport->enqueuedCount());
    }

    // ── PHPUnit Assertions Trait ────────────────────────────

    public function testAssertEnqueued(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);
        $this->assertEnqueued($this->transport, 'email.send');
        $this->assertEnqueued($this->transport, 'email.send', ['a@b.com']);
    }

    public function testRefuteEnqueued(): void
    {
        $this->client->enqueue('email.send', []);
        $this->refuteEnqueued($this->transport, 'sms.send');
    }

    public function testAssertEnqueuedCount(): void
    {
        $this->client->enqueue('email.send', ['a@b.com']);
        $this->client->enqueue('email.send', ['c@d.com']);

        $this->assertEnqueuedCount($this->transport, 2, 'email.send');
        $this->assertEnqueuedCount($this->transport, 2);
    }

    public function testAssertCompleted(): void
    {
        $this->client->enqueue('test', []);
        $this->transport->drain(['test' => fn(JobContext $ctx) => 'ok']);
        $this->assertCompleted($this->transport, 'test');
    }

    public function testAssertFailed(): void
    {
        $this->client->enqueue('fail', []);
        $this->transport->drain(['fail' => function () {
            throw new \RuntimeException('boom');
        }]);
        $this->assertFailed($this->transport, 'fail');
    }

    // ── Full Integration Scenario ───────────────────────────

    public function testFullWorkflow(): void
    {
        // Enqueue with retry policy
        $job = $this->client->enqueue('report.generate', [42], [
            'queue' => 'reports',
            'priority' => 10,
            'retry' => ['max_attempts' => 5],
            'meta' => ['user_id' => 'u1'],
        ]);

        $this->assertEnqueued($this->transport, 'report.generate');

        // Process
        $this->transport->drain([
            'report.generate' => function (JobContext $ctx) {
                $this->assertEquals(42, $ctx->job->args[0]);
                return ['url' => 'https://example.com/report.pdf'];
            },
        ]);

        $this->assertCompleted($this->transport, 'report.generate');
    }

    // ── Batch Enqueue in Testing ────────────────────────────

    public function testBatchEnqueue(): void
    {
        $jobs = $this->client->enqueueBatch([
            ['type' => 'email.send', 'args' => ['a@b.com']],
            ['type' => 'email.send', 'args' => ['c@d.com']],
        ]);

        $this->assertCount(2, $jobs);
        $this->assertEnqueuedCount($this->transport, 2, 'email.send');
    }

    // ── Cancel in Testing ───────────────────────────────────

    public function testCancelJob(): void
    {
        $job = $this->client->enqueue('test.cancel', []);
        $cancelled = $this->client->cancel($job->id);
        $this->assertEquals('cancelled', $cancelled->state);
    }

    // ── Cron Jobs in Testing ────────────────────────────────

    public function testCronJobCRUD(): void
    {
        $cron = new \OpenJobSpec\CronJob(
            name: 'test-cron',
            cron: '*/5 * * * *',
            type: 'test.cron',
        );

        $this->client->registerCronJob($cron);
        $jobs = $this->client->listCronJobs();
        $this->assertCount(1, $jobs);

        $this->client->unregisterCronJob('test-cron');
        $jobs = $this->client->listCronJobs();
        $this->assertCount(0, $jobs);
    }

    // ── Dead Letter in Testing ──────────────────────────────

    public function testDeadLetterFlow(): void
    {
        // Enqueue and fail a job
        $this->client->enqueue('dl.test', []);
        $this->transport->drain(['dl.test' => function () {
            throw new \RuntimeException('permanent failure');
        }]);

        // Verify it's in dead letter
        $deadJobs = $this->client->getDeadLetterJobs();
        $this->assertCount(1, $deadJobs);

        // Retry it
        $retried = $this->client->retryDeadLetter($deadJobs[0]->id);
        $this->assertEquals('available', $retried->state);
    }

    // ── Workflow Submission ─────────────────────────────────

    public function testWorkflowSubmission(): void
    {
        $workflow = \OpenJobSpec\Workflow::chain('test-chain', [
            new \OpenJobSpec\Step(type: 'step.one', args: [1]),
            new \OpenJobSpec\Step(type: 'step.two', args: [2]),
        ]);

        $result = $this->client->workflow($workflow);
        $this->assertArrayHasKey('id', $result);
    }

    // ── Health & Manifest ───────────────────────────────────

    public function testHealthAndManifest(): void
    {
        $health = $this->client->health();
        $this->assertEquals('ok', $health['status']);

        $manifest = $this->client->manifest();
        $this->assertEquals(4, $manifest['level']);
    }
}

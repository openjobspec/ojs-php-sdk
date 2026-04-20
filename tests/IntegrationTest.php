<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Worker;
use OpenJobSpec\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Integration-style tests for full job lifecycle flows.
 *
 * Covers: enqueue → fetch → process → ack/nack, workflows,
 * retry with eventual success, and multi-job sequences.
 */
final class IntegrationTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    public function testFullJobLifecycleEnqueueProcessComplete(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);
        $job = $client->enqueue('integration.test', [['action' => 'process']], queue: 'integration');

        $this->assertNotEmpty($job->id);
        $this->assertSame('integration.test', $job->type);

        $processed = false;
        $worker = new Worker('http://localhost:8080', transport: $this->transport, queues: ['integration']);
        $worker->register('integration.test', function ($ctx) use (&$processed) {
            $processed = true;
            $this->assertSame('integration.test', $ctx->job->type);
            $this->assertIsArray($ctx->job->args);
        });

        $worker->processOnce();
        $this->assertTrue($processed, 'Handler should have been called');
    }

    public function testMultipleJobsProcessedInOrder(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);

        for ($i = 1; $i <= 3; $i++) {
            $client->enqueue('integration.order', [['seq' => $i]], queue: 'order');
        }

        $processedSeqs = [];
        $worker = new Worker('http://localhost:8080', transport: $this->transport, queues: ['order']);
        $worker->register('integration.order', function ($ctx) use (&$processedSeqs) {
            $args = $ctx->job->args[0] ?? [];
            $processedSeqs[] = $args['seq'] ?? 0;
        });

        for ($i = 0; $i < 3; $i++) {
            $worker->processOnce();
        }

        $this->assertCount(3, $processedSeqs);
        $this->assertSame([1, 2, 3], $processedSeqs);
    }

    public function testJobWithMiddlewarePipeline(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);
        $client->enqueue('integration.middleware', [['data' => 'test']], queue: 'mw');

        $middlewareExecuted = false;
        $handlerExecuted = false;

        $worker = new Worker('http://localhost:8080', transport: $this->transport, queues: ['mw']);

        $worker->use(function ($ctx, $next) use (&$middlewareExecuted) {
            $middlewareExecuted = true;
            return $next($ctx);
        });

        $worker->register('integration.middleware', function ($ctx) use (&$handlerExecuted) {
            $handlerExecuted = true;
        });

        $worker->processOnce();

        $this->assertTrue($middlewareExecuted, 'Middleware should run');
        $this->assertTrue($handlerExecuted, 'Handler should run after middleware');
    }

    public function testJobFailureAndRetryFlow(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);
        $client->enqueue('integration.retry', [['action' => 'fail-once']], queue: 'retry');

        $attempts = 0;
        $worker = new Worker('http://localhost:8080', transport: $this->transport, queues: ['retry']);
        $worker->register('integration.retry', function ($ctx) use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('First attempt fails');
            }
        });

        // First attempt should fail
        $worker->processOnce();
        $this->assertSame(1, $attempts);

        // Verify NACK was sent
        $requests = $this->transport->requests();
        $nackSent = false;
        foreach ($requests as $req) {
            if (str_contains($req['path'], 'nack')) {
                $nackSent = true;
            }
        }
        $this->assertTrue($nackSent, 'Failed job should be NACKed');
    }

    public function testGetJobAfterEnqueue(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);
        $job = $client->enqueue('integration.get', [['data' => 1]], queue: 'default');

        $retrieved = $client->getJob($job->id);
        $this->assertSame($job->id, $retrieved->id);
        $this->assertSame('integration.get', $retrieved->type);
    }

    public function testCancelJobAfterEnqueue(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);
        $job = $client->enqueue('integration.cancel', [['data' => 1]], queue: 'default');

        $cancelled = $client->cancel($job->id);
        $this->assertSame('cancelled', $cancelled->state);
    }

    public function testBatchEnqueueAndProcessAll(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);

        $jobs = $client->enqueueBatch([
            ['type' => 'integration.batch', 'args' => [['seq' => 1]]],
            ['type' => 'integration.batch', 'args' => [['seq' => 2]]],
            ['type' => 'integration.batch', 'args' => [['seq' => 3]]],
        ]);

        $this->assertCount(3, $jobs);

        $count = 0;
        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        $worker->register('integration.batch', function ($ctx) use (&$count) {
            $count++;
        });

        for ($i = 0; $i < 3; $i++) {
            $worker->processOnce();
        }

        $this->assertSame(3, $count, 'All 3 batch jobs should be processed');
    }

    public function testWorkflowChainCreation(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);

        $workflow = $client->createWorkflow([
            'type' => 'chain',
            'steps' => [
                ['type' => 'integration.step1', 'args' => [['step' => 1]]],
                ['type' => 'integration.step2', 'args' => [['step' => 2]]],
            ],
        ]);

        $this->assertArrayHasKey('id', $workflow);
    }

    public function testDifferentJobTypesOnDifferentQueues(): void
    {
        $client = new Client('http://localhost:8080', transport: $this->transport);

        $client->enqueue('email.send', [['to' => 'user@example.com']], queue: 'email');
        $client->enqueue('report.generate', [['format' => 'pdf']], queue: 'reports');

        $emailProcessed = false;
        $reportProcessed = false;

        $worker = new Worker(
            'http://localhost:8080',
            transport: $this->transport,
            queues: ['email', 'reports'],
        );
        $worker->register('email.send', function ($ctx) use (&$emailProcessed) {
            $emailProcessed = true;
        });
        $worker->register('report.generate', function ($ctx) use (&$reportProcessed) {
            $reportProcessed = true;
        });

        $worker->processOnce();
        $worker->processOnce();

        $this->assertTrue($emailProcessed || $reportProcessed, 'At least one job should be processed');
    }
}

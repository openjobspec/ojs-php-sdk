<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{Worker, Job, JobContext, Middleware, MiddlewareChain, LoggingMiddleware, MetricsMiddleware};
use OpenJobSpec\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

class WorkerTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    public function testRegisterHandler(): void
    {
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $result = $worker->register('test.job', fn(JobContext $ctx) => 'ok');

        $this->assertInstanceOf(Worker::class, $result);
    }

    public function testDefaultFetchUsesDefaultQueueOnly(): void
    {
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('email.send', fn(JobContext $ctx) => 'ok');

        $worker->processOnce();

        $fetch = $this->transport->requests()[0];
        $this->assertSame('/ojs/v1/workers/fetch', $fetch['path']);
        $this->assertSame(['default'], $fetch['body']['queues']);
        $this->assertNotContains('email.send', $fetch['body']['queues']);
    }

    public function testExplicitFetchQueuesRemainVerbatim(): void
    {
        $queues = ['critical', 'critical', 'low.priority'];
        $worker = new Worker('http://fake', [
            'transport' => $this->transport,
            'queues' => $queues,
        ]);
        $worker->register('unrelated.handler.type', fn(JobContext $ctx) => 'ok');

        $worker->processOnce();

        $fetch = $this->transport->requests()[0];
        $this->assertSame($queues, $fetch['body']['queues']);
        $this->assertNotContains('unrelated.handler.type', $fetch['body']['queues']);
    }

    public function testProcessOnce(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'test.echo', 'args' => ['hello']]);

        $processed = false;
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('test.echo', function (JobContext $ctx) use (&$processed) {
            $processed = true;
            $this->assertEquals('test.echo', $ctx->job->type);
            $this->assertEquals(['hello'], $ctx->job->args);
            return 'done';
        });

        $worker->processOnce();
        $this->assertTrue($processed);
    }

    public function testProcessWithMiddleware(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'test.mw', 'args' => []]);

        $middlewareCalled = false;
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->use('test', function (JobContext $ctx, callable $next) use (&$middlewareCalled) {
            $middlewareCalled = true;
            $ctx->store['middleware_ran'] = true;
            return $next();
        });
        $worker->register('test.mw', function (JobContext $ctx) {
            $this->assertTrue($ctx->store['middleware_ran'] ?? false);
            return 'ok';
        });

        $worker->processOnce();
        $this->assertTrue($middlewareCalled);
    }

    public function testProcessWithFailingHandler(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'test.fail', 'args' => []]);

        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('test.fail', function (JobContext $ctx) {
            throw new \RuntimeException('Something went wrong');
        });

        $failedEvent = null;
        $worker->on('job.failed', function ($event) use (&$failedEvent) {
            $failedEvent = $event;
        });

        $worker->processOnce();

        $this->assertNotNull($failedEvent);
        $this->assertEquals('job.failed', $failedEvent->type);
    }

    public function testEventListeners(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'test.events', 'args' => []]);

        $events = [];
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('test.events', fn(JobContext $ctx) => 'ok');

        $worker->on('job.started', function ($e) use (&$events) {
            $events[] = $e->type;
        });
        $worker->on('job.completed', function ($e) use (&$events) {
            $events[] = $e->type;
        });

        $worker->processOnce();

        $this->assertContains('job.started', $events);
        $this->assertContains('job.completed', $events);
    }

    public function testWildcardEventListener(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'test.wild', 'args' => []]);

        $events = [];
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('test.wild', fn(JobContext $ctx) => 'ok');
        $worker->on('*', function ($e) use (&$events) {
            $events[] = $e->type;
        });

        $worker->processOnce();
        $this->assertNotEmpty($events);
    }

    public function testUnregisteredHandlerNacks(): void
    {
        $this->transport->post('/ojs/v1/jobs', ['type' => 'unknown.type', 'args' => []]);

        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $worker->register('other.type', fn(JobContext $ctx) => 'ok');
        $worker->processOnce();

        // Job should be NACKed (discarded in fake transport)
        $this->assertTrue($this->transport->failed('unknown.type'));
    }

    public function testStopAndQuiet(): void
    {
        $worker = new Worker('http://fake', ['transport' => $this->transport]);
        $this->assertFalse($worker->isRunning());
        $this->assertFalse($worker->isQuiet());

        $worker->quiet();
        $this->assertTrue($worker->isQuiet());

        $worker->stop();
        $this->assertFalse($worker->isRunning());
    }

    public function testWorkerStoppedEventRetainsEmptyPayload(): void
    {
        $worker = new Worker('http://fake', [
            'transport' => $this->transport,
            'shutdown_timeout' => 1.0,
        ]);
        $stoppedData = null;

        $worker->on('worker.started', fn() => $worker->stop());
        $worker->on('worker.stopped', function ($event) use (&$stoppedData): void {
            $stoppedData = $event->data;
        });

        $worker->start();

        $this->assertSame([], $stoppedData);
    }
}

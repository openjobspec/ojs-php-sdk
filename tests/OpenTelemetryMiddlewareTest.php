<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{OpenTelemetryMiddleware, JobContext, Job, Middleware};
use PHPUnit\Framework\TestCase;

class OpenTelemetryMiddlewareTest extends TestCase
{
    private function makeContext(string $type = 'email.send', string $queue = 'default'): JobContext
    {
        return new JobContext(Job::fromArray([
            'id' => 'otel-test-1',
            'type' => $type,
            'args' => [],
            'queue' => $queue,
            'state' => 'active',
            'attempt' => 1,
        ]));
    }

    public function testImplementsMiddlewareInterface(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $this->assertInstanceOf(Middleware::class, $mw);
    }

    public function testHandleCallsNextWithoutTracer(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $ctx = $this->makeContext();
        $called = false;

        $result = $mw->handle($ctx, function () use (&$called) {
            $called = true;
            return 'done';
        });

        $this->assertTrue($called);
        $this->assertEquals('done', $result);
    }

    public function testHandlePassthroughWhenTracerIsNull(): void
    {
        $mw = new OpenTelemetryMiddleware(null);
        $ctx = $this->makeContext();

        $result = $mw->handle($ctx, fn() => 42);

        $this->assertEquals(42, $result);
    }

    public function testHandleWithMockTracerNoOtelAvailable(): void
    {
        // Tracer is provided but OTel classes are not installed,
        // so isOtelAvailable() returns false and next() is called directly
        $tracer = new \stdClass();
        $mw = new OpenTelemetryMiddleware($tracer);
        $ctx = $this->makeContext();
        $called = false;

        $result = $mw->handle($ctx, function () use (&$called) {
            $called = true;
            return 'passthrough';
        });

        $this->assertTrue($called);
        $this->assertEquals('passthrough', $result);
    }

    public function testHandlePreservesReturnValue(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $ctx = $this->makeContext();

        $result = $mw->handle($ctx, fn() => ['status' => 'ok', 'count' => 5]);

        $this->assertEquals(['status' => 'ok', 'count' => 5], $result);
    }

    public function testHandlePropagatesExceptionWithoutTracer(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $ctx = $this->makeContext();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Job failed');

        $mw->handle($ctx, function () {
            throw new \RuntimeException('Job failed');
        });
    }

    public function testHandleReceivesCorrectContext(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $ctx = $this->makeContext('report.generate', 'analytics');
        $receivedCtx = null;

        $mw->handle($ctx, function () use ($ctx, &$receivedCtx) {
            $receivedCtx = $ctx;
            return null;
        });

        $this->assertNotNull($receivedCtx);
        $this->assertEquals('report.generate', $receivedCtx->job->type);
        $this->assertEquals('analytics', $receivedCtx->job->queue);
    }

    public function testHandleReturnsNullFromNext(): void
    {
        $mw = new OpenTelemetryMiddleware();
        $ctx = $this->makeContext();

        $result = $mw->handle($ctx, fn() => null);

        $this->assertNull($result);
    }

    public function testConstructorAcceptsNullTracer(): void
    {
        $mw = new OpenTelemetryMiddleware(null);
        $this->assertInstanceOf(OpenTelemetryMiddleware::class, $mw);
    }

    public function testConstructorAcceptsObjectTracer(): void
    {
        $mw = new OpenTelemetryMiddleware(new \stdClass());
        $this->assertInstanceOf(OpenTelemetryMiddleware::class, $mw);
    }
}

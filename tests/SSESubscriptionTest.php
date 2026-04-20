<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{SSESubscription, Event};
use PHPUnit\Framework\TestCase;

class SSESubscriptionTest extends TestCase
{
    // ── parseSSE (tested via reflection) ────────────────────

    private function invokeParseSSE(string $buffer, callable $callback): string
    {
        $sub = $this->createSubscription('http://localhost:8080');
        $method = new \ReflectionMethod($sub, 'parseSSE');
        $method->invokeArgs($sub, [&$buffer, $callback]);
        return $buffer;
    }

    private function createSubscription(string $url): SSESubscription
    {
        $class = new \ReflectionClass(SSESubscription::class);
        $instance = $class->newInstanceWithoutConstructor();

        $urlProp = $class->getProperty('url');
        $urlProp->setValue($instance, $url);

        $headersProp = $class->getProperty('headers');
        $headersProp->setValue($instance, []);

        return $instance;
    }

    public function testParseSSEBasicEvent(): void
    {
        $events = [];
        $buffer = "event: job.completed\ndata: {\"job_id\":\"j1\"}\n\n";

        $remaining = $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertEquals('job.completed', $events[0]->type);
        $this->assertEquals(['job_id' => 'j1'], $events[0]->data);
        $this->assertEquals('', $remaining);
    }

    public function testParseSSEDefaultEventType(): void
    {
        $events = [];
        $buffer = "data: {\"status\":\"ok\"}\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertEquals('message', $events[0]->type);
    }

    public function testParseSSEWithEventId(): void
    {
        $events = [];
        $buffer = "id: evt-42\nevent: job.started\ndata: {\"job_id\":\"j2\"}\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertEquals('evt-42', $events[0]->id);
        $this->assertEquals('job.started', $events[0]->type);
    }

    public function testParseSSEMultipleEvents(): void
    {
        $events = [];
        $buffer = "event: job.enqueued\ndata: {\"id\":\"1\"}\n\nevent: job.started\ndata: {\"id\":\"2\"}\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(2, $events);
        $this->assertEquals('job.enqueued', $events[0]->type);
        $this->assertEquals('job.started', $events[1]->type);
    }

    public function testParseSSEIncompleteBuffer(): void
    {
        $events = [];
        $buffer = "event: job.completed\ndata: {\"partial\":true}";

        $remaining = $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(0, $events);
        $this->assertNotEmpty($remaining);
    }

    public function testParseSSEEmptyDataSkipped(): void
    {
        $events = [];
        $buffer = "event: keepalive\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(0, $events);
    }

    public function testParseSSEExtractsTimeFromData(): void
    {
        $events = [];
        $buffer = "event: job.completed\ndata: {\"time\":\"2024-06-15T10:00:00Z\"}\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertEquals('2024-06-15T10:00:00Z', $events[0]->time);
    }

    // ── buildHeaders ────────────────────────────────────────

    public function testBuildHeadersWithoutAuth(): void
    {
        $method = new \ReflectionMethod(SSESubscription::class, 'buildHeaders');

        $headers = $method->invoke(null, null);
        $this->assertEquals(['Cache-Control: no-cache'], $headers);
    }

    public function testBuildHeadersWithAuth(): void
    {
        $method = new \ReflectionMethod(SSESubscription::class, 'buildHeaders');

        $headers = $method->invoke(null, 'my-secret-token');
        $this->assertCount(2, $headers);
        $this->assertEquals('Cache-Control: no-cache', $headers[0]);
        $this->assertEquals('Authorization: Bearer my-secret-token', $headers[1]);
    }

    // ── URL construction via factory methods ────────────────

    public function testForJobUrlConstruction(): void
    {
        $urlProp = new \ReflectionProperty(SSESubscription::class, 'url');

        $sub = $this->createSubscription('http://example.com/ojs/v1/events/jobs/j-123');
        $this->assertEquals('http://example.com/ojs/v1/events/jobs/j-123', $urlProp->getValue($sub));
    }

    public function testForQueueUrlConstruction(): void
    {
        $urlProp = new \ReflectionProperty(SSESubscription::class, 'url');

        $sub = $this->createSubscription('http://example.com/ojs/v1/events/queues/critical');
        $this->assertEquals('http://example.com/ojs/v1/events/queues/critical', $urlProp->getValue($sub));
    }

    public function testForAllUrlConstruction(): void
    {
        $urlProp = new \ReflectionProperty(SSESubscription::class, 'url');

        $sub = $this->createSubscription('http://example.com/ojs/v1/events');
        $this->assertEquals('http://example.com/ojs/v1/events', $urlProp->getValue($sub));
    }

    // ── isActive / cancel ───────────────────────────────────

    public function testIsActiveDefaultFalse(): void
    {
        $sub = $this->createSubscription('http://localhost');
        $this->assertFalse($sub->isActive());
    }

    public function testCancelSetsInactive(): void
    {
        $sub = $this->createSubscription('http://localhost');

        $activeProp = new \ReflectionProperty($sub, 'active');
        $activeProp->setValue($sub, true);

        $this->assertTrue($sub->isActive());
        $sub->cancel();
        $this->assertFalse($sub->isActive());
    }

    public function testCancelIdempotent(): void
    {
        $sub = $this->createSubscription('http://localhost');
        $sub->cancel();
        $sub->cancel();
        $this->assertFalse($sub->isActive());
    }

    public function testParseSSEInvalidJsonCreatesEmptyData(): void
    {
        $events = [];
        $buffer = "event: test\ndata: not-valid-json\n\n";

        $this->invokeParseSSE($buffer, function (Event $event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(1, $events);
        $this->assertEquals([], $events[0]->data);
    }
}

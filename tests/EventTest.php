<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Event;
use PHPUnit\Framework\TestCase;

class EventTest extends TestCase
{
    // ── Constants ───────────────────────────────────────────

    public function testJobEventConstants(): void
    {
        $this->assertEquals('job.enqueued', Event::JOB_ENQUEUED);
        $this->assertEquals('job.started', Event::JOB_STARTED);
        $this->assertEquals('job.completed', Event::JOB_COMPLETED);
        $this->assertEquals('job.failed', Event::JOB_FAILED);
        $this->assertEquals('job.retrying', Event::JOB_RETRYING);
        $this->assertEquals('job.cancelled', Event::JOB_CANCELLED);
        $this->assertEquals('job.heartbeat', Event::JOB_HEARTBEAT);
        $this->assertEquals('job.scheduled', Event::JOB_SCHEDULED);
        $this->assertEquals('job.expired', Event::JOB_EXPIRED);
        $this->assertEquals('job.progress', Event::JOB_PROGRESS);
    }

    public function testQueueEventConstants(): void
    {
        $this->assertEquals('queue.paused', Event::QUEUE_PAUSED);
        $this->assertEquals('queue.resumed', Event::QUEUE_RESUMED);
    }

    public function testWorkerEventConstants(): void
    {
        $this->assertEquals('worker.started', Event::WORKER_STARTED);
        $this->assertEquals('worker.stopped', Event::WORKER_STOPPED);
        $this->assertEquals('worker.quiet', Event::WORKER_QUIET);
        $this->assertEquals('worker.heartbeat', Event::WORKER_HEARTBEAT);
    }

    public function testWorkflowEventConstants(): void
    {
        $this->assertEquals('workflow.started', Event::WORKFLOW_STARTED);
        $this->assertEquals('workflow.completed', Event::WORKFLOW_COMPLETED);
        $this->assertEquals('workflow.failed', Event::WORKFLOW_FAILED);
        $this->assertEquals('workflow.step.completed', Event::WORKFLOW_STEP_COMPLETED);
    }

    public function testCronEventConstants(): void
    {
        $this->assertEquals('cron.triggered', Event::CRON_TRIGGERED);
        $this->assertEquals('cron.skipped', Event::CRON_SKIPPED);
    }

    // ── Construction ────────────────────────────────────────

    public function testConstructorWithRequiredFields(): void
    {
        $event = new Event(type: 'job.enqueued');

        $this->assertEquals('job.enqueued', $event->type);
        $this->assertEquals([], $event->data);
        $this->assertNull($event->id);
        $this->assertNull($event->source);
        $this->assertNull($event->time);
        $this->assertNull($event->subject);
    }

    public function testConstructorWithAllFields(): void
    {
        $event = new Event(
            type: 'job.completed',
            data: ['job_id' => 'j-123', 'result' => 'ok'],
            id: 'evt-456',
            source: '/ojs/backend',
            time: '2024-06-15T10:30:00Z',
            subject: 'job/j-123',
        );

        $this->assertEquals('job.completed', $event->type);
        $this->assertEquals(['job_id' => 'j-123', 'result' => 'ok'], $event->data);
        $this->assertEquals('evt-456', $event->id);
        $this->assertEquals('/ojs/backend', $event->source);
        $this->assertEquals('2024-06-15T10:30:00Z', $event->time);
        $this->assertEquals('job/j-123', $event->subject);
    }

    // ── toArray ─────────────────────────────────────────────

    public function testToArrayMinimal(): void
    {
        $event = new Event(type: 'job.started');
        $arr = $event->toArray();

        $this->assertEquals('job.started', $arr['type']);
        $this->assertEquals([], $arr['data']);
        $this->assertArrayNotHasKey('id', $arr);
        $this->assertArrayNotHasKey('source', $arr);
        $this->assertArrayNotHasKey('time', $arr);
        $this->assertArrayNotHasKey('subject', $arr);
    }

    public function testToArrayWithAllOptionalFields(): void
    {
        $event = new Event(
            type: 'workflow.completed',
            data: ['steps' => 3],
            id: 'e1',
            source: '/ojs',
            time: '2024-01-01T00:00:00Z',
            subject: 'workflow/w1',
        );

        $arr = $event->toArray();

        $this->assertEquals('workflow.completed', $arr['type']);
        $this->assertEquals(['steps' => 3], $arr['data']);
        $this->assertEquals('e1', $arr['id']);
        $this->assertEquals('/ojs', $arr['source']);
        $this->assertEquals('2024-01-01T00:00:00Z', $arr['time']);
        $this->assertEquals('workflow/w1', $arr['subject']);
    }

    public function testToArrayOmitsNullOptionalFields(): void
    {
        $event = new Event(type: 'test', data: ['x' => 1], id: 'e1');
        $arr = $event->toArray();

        $this->assertArrayHasKey('id', $arr);
        $this->assertArrayNotHasKey('source', $arr);
        $this->assertArrayNotHasKey('time', $arr);
        $this->assertArrayNotHasKey('subject', $arr);
    }

    // ── fromArray ───────────────────────────────────────────

    public function testFromArrayMinimal(): void
    {
        $event = Event::fromArray(['type' => 'job.failed']);

        $this->assertEquals('job.failed', $event->type);
        $this->assertEquals([], $event->data);
        $this->assertNull($event->id);
        $this->assertNull($event->source);
        $this->assertNull($event->time);
        $this->assertNull($event->subject);
    }

    public function testFromArrayWithAllFields(): void
    {
        $event = Event::fromArray([
            'type' => 'cron.triggered',
            'data' => ['cron_name' => 'daily'],
            'id' => 'c-evt-1',
            'source' => '/scheduler',
            'time' => '2024-03-20T08:00:00Z',
            'subject' => 'cron/daily',
        ]);

        $this->assertEquals('cron.triggered', $event->type);
        $this->assertEquals(['cron_name' => 'daily'], $event->data);
        $this->assertEquals('c-evt-1', $event->id);
        $this->assertEquals('/scheduler', $event->source);
        $this->assertEquals('2024-03-20T08:00:00Z', $event->time);
        $this->assertEquals('cron/daily', $event->subject);
    }

    public function testFromArrayEmptyDefaults(): void
    {
        $event = Event::fromArray([]);

        $this->assertEquals('', $event->type);
        $this->assertEquals([], $event->data);
        $this->assertNull($event->id);
    }

    // ── Roundtrip ───────────────────────────────────────────

    public function testSerializationRoundtrip(): void
    {
        $original = new Event(
            type: Event::JOB_COMPLETED,
            data: ['duration_ms' => 1234, 'result' => ['status' => 'ok']],
            id: 'roundtrip-1',
            source: '/ojs/v1',
            time: '2024-12-01T15:00:00Z',
            subject: 'job/abc',
        );

        $restored = Event::fromArray($original->toArray());

        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->data, $restored->data);
        $this->assertEquals($original->id, $restored->id);
        $this->assertEquals($original->source, $restored->source);
        $this->assertEquals($original->time, $restored->time);
        $this->assertEquals($original->subject, $restored->subject);
    }

    public function testRoundtripMinimalEvent(): void
    {
        $original = new Event(type: 'worker.quiet');
        $restored = Event::fromArray($original->toArray());

        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->data, $restored->data);
        $this->assertNull($restored->id);
    }

    // ── Data contents ───────────────────────────────────────

    public function testEventWithNestedData(): void
    {
        $data = [
            'job' => ['id' => 'j1', 'type' => 'email.send'],
            'error' => ['message' => 'timeout', 'code' => 504],
        ];

        $event = new Event(type: Event::JOB_FAILED, data: $data);
        $arr = $event->toArray();

        $this->assertEquals($data, $arr['data']);
        $this->assertEquals('j1', $arr['data']['job']['id']);
    }

    public function testEventWithEmptyData(): void
    {
        $event = new Event(type: 'job.heartbeat', data: []);
        $this->assertEquals([], $event->toArray()['data']);
    }
}

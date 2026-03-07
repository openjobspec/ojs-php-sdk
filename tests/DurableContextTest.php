<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\DurableContext;
use OpenJobSpec\Job;
use OpenJobSpec\OjsException;
use OpenJobSpec\Transport;
use PHPUnit\Framework\TestCase;

/**
 * In-memory transport that simulates the checkpoint endpoints.
 * GET returns the last saved body (or throws 404).
 */
class CheckpointTransport implements Transport
{
    public ?array $saved = null;
    public bool $deleteCalled = false;

    public function post(string $path, array $body = []): array
    {
        $this->saved = $body;
        return [];
    }

    public function get(string $path, array $query = []): array
    {
        if ($this->saved !== null) {
            return $this->saved;
        }
        throw new OjsException('Not found', 404);
    }

    public function delete(string $path): array
    {
        $this->deleteCalled = true;
        return [];
    }
}

class DurableContextTest extends TestCase
{
    private CheckpointTransport $transport;
    private Job $job;

    protected function setUp(): void
    {
        $this->transport = new CheckpointTransport();
        $this->job = Job::fromArray([
            'id'    => 'test-job-001',
            'type'  => 'test.type',
            'args'  => [],
            'queue' => 'default',
            'state' => 'active',
        ]);
    }

    // ── now() ───────────────────────────────────────────────

    public function testNowReturnsDateTimeImmutable(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $t = $ctx->now();
        $this->assertInstanceOf(\DateTimeImmutable::class, $t);
    }

    public function testNowRecordsEntryInReplayLog(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $ctx->now();
        $this->assertCount(1, $ctx->getReplayLog());
    }

    public function testNowReplaysTheSameValue(): void
    {
        $ctx1 = new DurableContext($this->transport, $this->job);
        $t1 = $ctx1->now();

        // Seed the transport with the recorded replay log
        $this->transport->saved = ['state' => ['replay_log' => $ctx1->getReplayLog()]];
        $ctx2 = DurableContext::create($this->transport, $this->job);
        $t2 = $ctx2->now();

        $this->assertEquals($t1->format(\DateTimeInterface::RFC3339_EXTENDED), $t2->format(\DateTimeInterface::RFC3339_EXTENDED));
    }

    // ── random() ────────────────────────────────────────────

    public function testRandomReturnsHexOfCorrectLength(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $hex = $ctx->random(16);
        $this->assertSame(32, strlen($hex));
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
    }

    public function testRandomRecordsEntryInReplayLog(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $ctx->random(8);
        $this->assertCount(1, $ctx->getReplayLog());
    }

    public function testRandomReplaysTheSameValue(): void
    {
        $ctx1 = new DurableContext($this->transport, $this->job);
        $hex1 = $ctx1->random(16);

        $this->transport->saved = ['state' => ['replay_log' => $ctx1->getReplayLog()]];
        $ctx2 = DurableContext::create($this->transport, $this->job);

        $this->assertSame($hex1, $ctx2->random(16));
    }

    // ── sideEffect() ───────────────────────────────────────

    public function testSideEffectExecutesCallableAndReturnsResult(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $result = $ctx->sideEffect('op', fn() => 'hello');
        $this->assertSame('hello', $result);
    }

    public function testSideEffectRecordsEntryInReplayLog(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $ctx->sideEffect('api-call', fn() => 42);
        $this->assertCount(1, $ctx->getReplayLog());
    }

    public function testSideEffectReplaysWithoutReExecuting(): void
    {
        $ctx1 = new DurableContext($this->transport, $this->job);
        $ctx1->sideEffect('op', fn() => 'original');

        $this->transport->saved = ['state' => ['replay_log' => $ctx1->getReplayLog()]];
        $ctx2 = DurableContext::create($this->transport, $this->job);

        $replayed = $ctx2->sideEffect('op', fn() => 'different');
        $this->assertSame('original', $replayed);
    }

    // ── Multi-operation replay ──────────────────────────────

    public function testMultipleOperationsReplayInOrder(): void
    {
        $ctx1 = new DurableContext($this->transport, $this->job);
        $t = $ctx1->now();
        $r = $ctx1->random(8);
        $s = $ctx1->sideEffect('fetch', fn() => 'data');
        $this->assertCount(3, $ctx1->getReplayLog());

        $this->transport->saved = ['state' => ['replay_log' => $ctx1->getReplayLog()]];
        $ctx2 = DurableContext::create($this->transport, $this->job);

        $this->assertEquals(
            $t->format(\DateTimeInterface::RFC3339_EXTENDED),
            $ctx2->now()->format(\DateTimeInterface::RFC3339_EXTENDED)
        );
        $this->assertSame($r, $ctx2->random(8));
        $this->assertSame('data', $ctx2->sideEffect('fetch', fn() => 'other'));
    }

    // ── save / resume / delete ──────────────────────────────

    public function testSavePersistsState(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $ctx->save(['step' => 3]);
        $this->assertNotNull($this->transport->saved);
        $this->assertSame(3, $this->transport->saved['state']['step']);
    }

    public function testResumeReturnsNullWhenNoCheckpoint(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $this->assertNull($ctx->resume());
    }

    public function testResumeReturnsSavedState(): void
    {
        $this->transport->saved = ['state' => ['step' => 5]];
        $ctx = new DurableContext($this->transport, $this->job);
        $this->assertSame(['step' => 5], $ctx->resume());
    }

    public function testDeleteDoesNotThrow(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $ctx->delete();
        $this->assertTrue($this->transport->deleteCalled);
    }

    // ── getReplayLog ────────────────────────────────────────

    public function testReplayLogIsEmptyForFreshContext(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $this->assertSame([], $ctx->getReplayLog());
    }

    public function testGetJobReturnsTheBoundJob(): void
    {
        $ctx = new DurableContext($this->transport, $this->job);
        $this->assertSame($this->job, $ctx->getJob());
    }
}

<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for input validation: invalid args, malformed payloads,
 * boundary conditions, and edge cases.
 */
final class InputValidationTest extends TestCase
{
    private FakeTransport $transport;
    private Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new Client('http://localhost:8080', transport: $this->transport);
    }

    public function testEmptyJobTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('', [['data' => 1]]);
    }

    public function testJobTypeWithInvalidCharsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('INVALID_TYPE', [['data' => 1]]);
    }

    public function testJobTypeWithSpacesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('invalid type', [['data' => 1]]);
    }

    public function testJobTypeOverMaximumLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue(str_repeat('a', 256));
    }

    public function testValidJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('valid.job_type', [['data' => 1]]);
        $this->assertSame('valid.job_type', $job->type);
    }

    public function testSingleSegmentJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('simple', [['data' => 1]]);
        $this->assertSame('simple', $job->type);
    }

    public function testDeeplyNestedJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('a.b.c.d.e', [['data' => 1]]);
        $this->assertSame('a.b.c.d.e', $job->type);
    }

    public function testEmptyArgsArrayIsAccepted(): void
    {
        $job = $this->client->enqueue('test.empty_args', []);
        $this->assertSame([], $job->args);
    }

    public function testArgsWithMultipleElementsIsAccepted(): void
    {
        $job = $this->client->enqueue('test.multi_args', ['hello', 42, true, null]);
        $this->assertSame(['hello', 42, true, null], $job->args);
    }

    public function testQueueNameWithHyphensIsAccepted(): void
    {
        $job = $this->client->enqueue('test.queue', [['data' => 1]], queue: 'my-queue');
        $this->assertSame('my-queue', $job->queue);
    }

    public function testQueueNameWithDotsIsAccepted(): void
    {
        $job = $this->client->enqueue('test.queue', [['data' => 1]], queue: 'my.queue');
        $this->assertSame('my.queue', $job->queue);
    }

    public function testEmptyQueueNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('test.queue', [['data' => 1]], queue: '');
    }

    public function testQueueNameOverMaximumLengthIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('test.queue', queue: str_repeat('a', 129));
    }

    public function testQueueNameWithInvalidCharsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client->enqueue('test.queue', queue: 'INVALID_QUEUE');
    }

    public function testLegacyInvalidArgumentCatchRemainsCompatible(): void
    {
        $caught = false;

        try {
            $this->client->enqueue('INVALID_TYPE');
        } catch (\InvalidArgumentException $error) {
            $caught = true;
            $this->assertStringContainsString('Invalid job type', $error->getMessage());
        }

        $this->assertTrue($caught, 'Existing InvalidArgumentException catches must continue to work.');
    }

    public function testPriorityZeroIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: 0);
        $this->assertSame(0, $job->priority);
    }

    public function testNegativePriorityIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: -5);
        $this->assertSame(-5, $job->priority);
    }

    public function testHighPriorityIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: 1000);
        $this->assertSame(1000, $job->priority);
    }

    public function testTimeoutZeroMeansNoTimeout(): void
    {
        $job = $this->client->enqueue('test.timeout', [['data' => 1]], timeout: 0);
        $this->assertSame(0, $job->timeout);
    }

    public function testMetaPreservation(): void
    {
        $meta = ['source' => 'test', 'version' => '1.0', 'nested' => ['key' => 'value']];
        $job = $this->client->enqueue('test.meta', [['data' => 1]], meta: $meta);
        $this->assertSame($meta, $job->meta);
    }

    public function testBatchEnqueueEmptyListIsDelegatedToServer(): void
    {
        $this->assertSame([], $this->client->enqueueBatch([]));

        $request = $this->transport->requests()[0];
        $this->assertSame('/ojs/v1/jobs/batch', $request['path']);
        $this->assertSame(['jobs' => []], $request['body']);
    }

    public function testBatchEnqueueWithValidJobs(): void
    {
        $jobs = $this->client->enqueueBatch([
            ['type' => 'test.batch1', 'args' => [['seq' => 1]]],
            ['type' => 'test.batch2', 'args' => [['seq' => 2]]],
        ]);
        $this->assertCount(2, $jobs);
    }

    public function testUnicodeArgsArePreserved(): void
    {
        $job = $this->client->enqueue('test.unicode', ['こんにちは', '🎉', 'café']);
        $this->assertSame(['こんにちは', '🎉', 'café'], $job->args);
    }

    public function testLargeArgsPayload(): void
    {
        $largeData = str_repeat('a', 10000);
        $job = $this->client->enqueue('test.large', [$largeData]);
        $this->assertSame($largeData, $job->args[0]);
    }
}

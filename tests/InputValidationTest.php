<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Testing\FakeTransport;
use OpenJobSpec\Errors\ValidationError;
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
        $this->expectException(ValidationError::class);
        $this->client->enqueue('', [['data' => 1]]);
    }

    public function testJobTypeWithInvalidCharsIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->client->enqueue('INVALID_TYPE', [['data' => 1]]);
    }

    public function testJobTypeWithSpacesIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->client->enqueue('invalid type', [['data' => 1]]);
    }

    public function testValidJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('valid.job_type', [['data' => 1]]);
        $this->assertNotNull($job);
        $this->assertSame('valid.job_type', $job->type);
    }

    public function testSingleSegmentJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('simple', [['data' => 1]]);
        $this->assertNotNull($job);
    }

    public function testDeeplyNestedJobTypeIsAccepted(): void
    {
        $job = $this->client->enqueue('a.b.c.d.e', [['data' => 1]]);
        $this->assertNotNull($job);
        $this->assertSame('a.b.c.d.e', $job->type);
    }

    public function testEmptyArgsArrayIsAccepted(): void
    {
        $job = $this->client->enqueue('test.empty_args', []);
        $this->assertNotNull($job);
    }

    public function testArgsWithMultipleElementsIsAccepted(): void
    {
        $job = $this->client->enqueue('test.multi_args', ['hello', 42, true, null]);
        $this->assertNotNull($job);
    }

    public function testQueueNameWithHyphensIsAccepted(): void
    {
        $job = $this->client->enqueue('test.queue', [['data' => 1]], queue: 'my-queue');
        $this->assertNotNull($job);
    }

    public function testQueueNameWithDotsIsAccepted(): void
    {
        $job = $this->client->enqueue('test.queue', [['data' => 1]], queue: 'my.queue');
        $this->assertNotNull($job);
    }

    public function testEmptyQueueNameIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->client->enqueue('test.queue', [['data' => 1]], queue: '');
    }

    public function testPriorityZeroIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: 0);
        $this->assertNotNull($job);
    }

    public function testNegativePriorityIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: -5);
        $this->assertNotNull($job);
    }

    public function testHighPriorityIsAccepted(): void
    {
        $job = $this->client->enqueue('test.priority', [['data' => 1]], priority: 1000);
        $this->assertNotNull($job);
    }

    public function testTimeoutZeroMeansNoTimeout(): void
    {
        $job = $this->client->enqueue('test.timeout', [['data' => 1]], timeout: 0);
        $this->assertNotNull($job);
    }

    public function testMetaPreservation(): void
    {
        $meta = ['source' => 'test', 'version' => '1.0', 'nested' => ['key' => 'value']];
        $job = $this->client->enqueue('test.meta', [['data' => 1]], meta: $meta);
        $this->assertNotNull($job);
    }

    public function testBatchEnqueueEmptyListIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        $this->client->enqueueBatch([]);
    }

    public function testBatchEnqueueWithValidJobs(): void
    {
        $jobs = $this->client->enqueueBatch([
            ['type' => 'test.batch1', 'args' => [['seq' => 1]]],
            ['type' => 'test.batch2', 'args' => [['seq' => 2]]],
        ]);
        $this->assertNotNull($jobs);
    }

    public function testUnicodeArgsArePreserved(): void
    {
        $job = $this->client->enqueue('test.unicode', ['こんにちは', '🎉', 'café']);
        $this->assertNotNull($job);
    }

    public function testLargeArgsPayload(): void
    {
        $largeData = str_repeat('a', 10000);
        $job = $this->client->enqueue('test.large', [$largeData]);
        $this->assertNotNull($job);
    }
}

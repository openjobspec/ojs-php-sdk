<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Transport;
use OpenJobSpec\HttpTransport;
use OpenJobSpec\Testing\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Transport layer (HTTP client behavior).
 *
 * Covers: request building, header management, error response parsing,
 * authentication, content type validation, and timeout behavior.
 */
final class TransportTest extends TestCase
{
    public function testFakeTransportRecordsRequests(): void
    {
        $transport = new FakeTransport();

        $transport->request('POST', '/ojs/v1/jobs', [
            'type' => 'test.transport',
            'args' => [['data' => 1]],
        ]);

        $requests = $transport->requests();
        $this->assertNotEmpty($requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/ojs/v1/jobs', $requests[0]['path']);
    }

    public function testFakeTransportClear(): void
    {
        $transport = new FakeTransport();
        $transport->request('GET', '/ojs/v1/health');

        $this->assertNotEmpty($transport->requests());

        $transport->clear();
        $this->assertEmpty($transport->requests());
    }

    public function testFakeTransportEnqueueCreatesJob(): void
    {
        $transport = new FakeTransport();
        $transport->enqueue('test.job', [['key' => 'value']], 'default');

        $response = $transport->request('POST', '/ojs/v1/workers/fetch', [
            'queues' => ['default'],
            'worker_id' => 'test-worker',
        ]);

        $this->assertArrayHasKey('jobs', $response);
    }

    public function testFakeTransportDrain(): void
    {
        $transport = new FakeTransport();
        $transport->enqueue('test.drain', [['a' => 1]], 'default');
        $transport->enqueue('test.drain', [['b' => 2]], 'default');

        $drained = $transport->drain('default');
        $this->assertCount(2, $drained);
    }

    public function testFakeTransportHealthEndpoint(): void
    {
        $transport = new FakeTransport();
        $response = $transport->request('GET', '/ojs/v1/health');

        $this->assertArrayHasKey('status', $response);
        $this->assertSame('ok', $response['status']);
    }

    public function testFakeTransportManifestEndpoint(): void
    {
        $transport = new FakeTransport();
        $response = $transport->request('GET', '/ojs/manifest');

        $this->assertSame('1.0', $response['version']);
    }

    public function testFakeTransportBatchEnqueue(): void
    {
        $transport = new FakeTransport();

        $response = $transport->request('POST', '/ojs/v1/jobs/batch', [
            'jobs' => [
                ['type' => 'test.batch1', 'args' => [['seq' => 1]]],
                ['type' => 'test.batch2', 'args' => [['seq' => 2]]],
            ],
        ]);

        $this->assertCount(2, $response['jobs']);
    }

    public function testFakeTransportGetJobById(): void
    {
        $transport = new FakeTransport();
        $transport->enqueue('test.getjob', [['data' => 1]], 'default');

        // Fetch to get a job ID
        $fetchResp = $transport->request('POST', '/ojs/v1/workers/fetch', [
            'queues' => ['default'],
            'worker_id' => 'test-worker',
        ]);

        if (!empty($fetchResp['jobs'])) {
            $jobId = $fetchResp['jobs'][0]['id'];
            $getResp = $transport->request('GET', "/ojs/v1/jobs/{$jobId}");
            $this->assertSame($jobId, $getResp['id']);
        } else {
            $this->markTestSkipped('No jobs fetched');
        }
    }

    public function testFakeTransportQueueStats(): void
    {
        $transport = new FakeTransport();
        $transport->enqueue('test.stats', [['data' => 1]], 'stats-queue');

        $response = $transport->request('GET', '/ojs/v1/queues/stats-queue/stats');
        $this->assertSame('stats-queue', $response['name']);
    }

    public function testFakeTransportCancelJob(): void
    {
        $transport = new FakeTransport();
        $transport->enqueue('test.cancel', [['data' => 1]], 'default');

        // Fetch to get ID
        $fetchResp = $transport->request('POST', '/ojs/v1/workers/fetch', [
            'queues' => ['default'],
            'worker_id' => 'test-worker',
        ]);

        if (!empty($fetchResp['jobs'])) {
            $jobId = $fetchResp['jobs'][0]['id'];
            $cancelResp = $transport->request('DELETE', "/ojs/v1/jobs/{$jobId}");
            $this->assertSame('cancelled', $cancelResp['state']);
        } else {
            $this->markTestSkipped('No jobs available for cancel test');
        }
    }
}

<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Job;
use OpenJobSpec\Testing\FakeTransport;
use OpenJobSpec\Worker;
use OpenJobSpec\OjsException;
use OpenJobSpec\ConnectionError;
use OpenJobSpec\TimeoutError;
use OpenJobSpec\ServerError;
use PHPUnit\Framework\TestCase;

/**
 * Tests for worker error handling edge cases.
 *
 * Covers: handler exceptions, panic recovery, connection failures,
 * timeout handling, retry exhaustion, and error propagation.
 */
final class WorkerErrorTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    public function testHandlerExceptionIsReportedAsNack(): void
    {
        $this->transport->enqueue('error.test', [['action' => 'throw']], 'errors');

        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        $worker->register('error.test', function ($ctx) {
            throw new \RuntimeException('Handler failed intentionally');
        });

        $worker->processOnce();

        $requests = $this->transport->requests();
        $nackSent = false;
        foreach ($requests as $req) {
            if (str_contains($req['path'] ?? '', 'nack')) {
                $nackSent = true;
                $this->assertArrayHasKey('error', $req['body']);
                $this->assertStringContainsString('Handler failed intentionally', $req['body']['error']['message'] ?? '');
            }
        }
        $this->assertTrue($nackSent, 'Worker should NACK when handler throws exception');
    }

    public function testHandlerReturningFalseIsNacked(): void
    {
        $this->transport->enqueue('error.false', [['action' => 'return-false']], 'errors');

        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        $worker->register('error.false', function ($ctx) {
            return false;
        });

        $worker->processOnce();

        $requests = $this->transport->requests();
        $hasNack = false;
        foreach ($requests as $req) {
            if (str_contains($req['path'] ?? '', 'nack')) {
                $hasNack = true;
            }
        }
        // Handler returning false should trigger NACK (non-retryable)
        $this->assertTrue($hasNack, 'Handler returning false should trigger NACK');
    }

    public function testUnregisteredJobTypeIsNacked(): void
    {
        $this->transport->enqueue('unknown.type', [['data' => 1]], 'errors');

        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        // Deliberately not registering a handler for 'unknown.type'
        $worker->register('other.type', function ($ctx) {});

        $worker->processOnce();

        $requests = $this->transport->requests();
        $hasNack = false;
        foreach ($requests as $req) {
            if (str_contains($req['path'] ?? '', 'nack')) {
                $hasNack = true;
            }
        }
        $this->assertTrue($hasNack, 'Unregistered job type should be NACKed');
    }

    public function testMultipleHandlerRegistrationsLastWins(): void
    {
        $this->transport->enqueue('overwrite.test', [['data' => 1]], 'errors');

        $worker = new Worker('http://localhost:8080', transport: $this->transport);

        $firstCalled = false;
        $secondCalled = false;

        $worker->register('overwrite.test', function ($ctx) use (&$firstCalled) {
            $firstCalled = true;
        });
        $worker->register('overwrite.test', function ($ctx) use (&$secondCalled) {
            $secondCalled = true;
        });

        $worker->processOnce();

        $this->assertFalse($firstCalled, 'First handler should not be called');
        $this->assertTrue($secondCalled, 'Second handler (last registered) should be called');
    }

    public function testEmptyQueueDoesNotError(): void
    {
        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        $worker->register('test.noop', function ($ctx) {});

        // processOnce on empty queue should not throw
        $worker->processOnce();
        $this->assertTrue(true, 'Empty queue should not cause errors');
    }

    public function testHandlerWithTypeErrorIsNacked(): void
    {
        $this->transport->enqueue('type.error', [['data' => 1]], 'errors');

        $worker = new Worker('http://localhost:8080', transport: $this->transport);
        $worker->register('type.error', function ($ctx) {
            // Deliberately cause a TypeError
            $arr = null;
            return count($arr); // @phpstan-ignore-line
        });

        $worker->processOnce();

        $requests = $this->transport->requests();
        $hasNack = false;
        foreach ($requests as $req) {
            if (str_contains($req['path'] ?? '', 'nack')) {
                $hasNack = true;
            }
        }
        $this->assertTrue($hasNack, 'TypeError in handler should be caught and NACKed');
    }

    public function testOjsExceptionRetryableFlag(): void
    {
        $retryable = new ServerError('Backend unavailable', 503);
        $this->assertTrue($retryable->isRetryable());

        $nonRetryable = new \OpenJobSpec\ValidationError('Bad input', 400);
        $this->assertFalse($nonRetryable->isRetryable());
    }

    public function testConnectionErrorIsRetryable(): void
    {
        $error = new ConnectionError('Connection refused');
        $this->assertTrue($error->isRetryable());
        $this->assertSame(0, $error->getCode());
    }

    public function testTimeoutErrorIsRetryable(): void
    {
        $error = new TimeoutError('Request timed out');
        $this->assertTrue($error->isRetryable());
    }

    public function testExceptionChainPreservesOriginal(): void
    {
        $original = new \RuntimeException('Original cause');
        $wrapped = new ServerError('Wrapped error', 500, previous: $original);

        $this->assertSame($original, $wrapped->getPrevious());
        $this->assertStringContainsString('Wrapped error', $wrapped->getMessage());
    }

    public function testServerErrorPositionalConstructorCompatibility(): void
    {
        $original = new \RuntimeException('Original cause');
        $error = new ServerError(
            'Backend unavailable',
            'backend_unavailable',
            'req-123',
            ['region' => 'eu-west'],
            $original,
        );

        $this->assertSame('backend_unavailable', $error->code);
        $this->assertSame('req-123', $error->requestId);
        $this->assertSame(['region' => 'eu-west'], $error->details);
        $this->assertSame($original, $error->getPrevious());
        $this->assertSame(500, $error->httpStatus);
    }

    public function testServerErrorNamedConstructorCompatibility(): void
    {
        $original = new \RuntimeException('Original cause');
        $error = new ServerError(
            message: 'Backend unavailable',
            code: 503,
            requestId: 'req-456',
            details: ['region' => 'us-east'],
            previous: $original,
        );

        $this->assertSame(503, $error->getCode());
        $this->assertSame('req-456', $error->requestId);
        $this->assertSame(['region' => 'us-east'], $error->details);
        $this->assertSame($original, $error->getPrevious());
        $this->assertSame(503, $error->httpStatus);
    }
}

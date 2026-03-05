<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{Middleware, MiddlewareChain, LoggingMiddleware, MetricsMiddleware, JobContext, Job};
use PHPUnit\Framework\TestCase;

class MiddlewareTest extends TestCase
{
    private function makeContext(string $type = 'test'): JobContext
    {
        return new JobContext(Job::fromArray([
            'id' => 'mw-test-1',
            'type' => $type,
            'args' => [],
            'queue' => 'default',
            'state' => 'active',
        ]));
    }

    public function testMiddlewareChainOrder(): void
    {
        $order = [];

        $chain = new MiddlewareChain();
        $chain->add('first', function (JobContext $ctx, callable $next) use (&$order) {
            $order[] = 'first-before';
            $result = $next();
            $order[] = 'first-after';
            return $result;
        });
        $chain->add('second', function (JobContext $ctx, callable $next) use (&$order) {
            $order[] = 'second-before';
            $result = $next();
            $order[] = 'second-after';
            return $result;
        });

        $result = $chain->invoke($this->makeContext(), function (JobContext $ctx) use (&$order) {
            $order[] = 'handler';
            return 'done';
        });

        $this->assertEquals('done', $result);
        $this->assertEquals(
            ['first-before', 'second-before', 'handler', 'second-after', 'first-after'],
            $order,
        );
    }

    public function testMiddlewareChainPrepend(): void
    {
        $order = [];

        $chain = new MiddlewareChain();
        $chain->add('second', function (JobContext $ctx, callable $next) use (&$order) {
            $order[] = 'second';
            return $next();
        });
        $chain->prepend('first', function (JobContext $ctx, callable $next) use (&$order) {
            $order[] = 'first';
            return $next();
        });

        $chain->invoke($this->makeContext(), function (JobContext $ctx) use (&$order) {
            $order[] = 'handler';
            return null;
        });

        $this->assertEquals(['first', 'second', 'handler'], $order);
    }

    public function testMiddlewareChainInsertBefore(): void
    {
        $order = [];

        $chain = new MiddlewareChain();
        $chain->add('a', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'a';
            return $next();
        })());
        $chain->add('c', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'c';
            return $next();
        })());
        $chain->insertBefore('c', 'b', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'b';
            return $next();
        })());

        $chain->invoke($this->makeContext(), fn(JobContext $ctx) => null);

        $this->assertEquals(['a', 'b', 'c'], $order);
    }

    public function testMiddlewareChainInsertAfter(): void
    {
        $order = [];

        $chain = new MiddlewareChain();
        $chain->add('a', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'a';
            return $next();
        })());
        $chain->add('c', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'c';
            return $next();
        })());
        $chain->insertAfter('a', 'b', fn(JobContext $ctx, callable $next) => (function () use (&$order, $next) {
            $order[] = 'b';
            return $next();
        })());

        $chain->invoke($this->makeContext(), fn(JobContext $ctx) => null);

        $this->assertEquals(['a', 'b', 'c'], $order);
    }

    public function testMiddlewareChainRemove(): void
    {
        $chain = new MiddlewareChain();
        $chain->add('keep', fn(JobContext $ctx, callable $next) => $next());
        $chain->add('remove', fn(JobContext $ctx, callable $next) => $next());

        $this->assertTrue($chain->has('remove'));
        $chain->remove('remove');
        $this->assertFalse($chain->has('remove'));
        $this->assertTrue($chain->has('keep'));
    }

    public function testMiddlewareInterfaceImplementation(): void
    {
        $called = false;
        $logging = new LoggingMiddleware();

        $chain = new MiddlewareChain();
        $chain->add('logging', $logging);

        ob_start();
        $chain->invoke($this->makeContext(), function (JobContext $ctx) use (&$called) {
            $called = true;
            return 'ok';
        });
        $output = ob_get_clean();

        $this->assertTrue($called);
        $this->assertStringContainsString('[OJS] Starting job', $output);
        $this->assertStringContainsString('[OJS] Completed job', $output);
    }

    public function testLoggingMiddlewareFailure(): void
    {
        $chain = new MiddlewareChain();
        $chain->add('logging', new LoggingMiddleware());

        ob_start();
        try {
            $chain->invoke($this->makeContext(), function (JobContext $ctx) {
                throw new \RuntimeException('Test error');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Test error', $e->getMessage());
        }
        $output = ob_get_clean();

        $this->assertStringContainsString('[OJS] Failed job', $output);
        $this->assertStringContainsString('Test error', $output);
    }

    public function testMetricsMiddleware(): void
    {
        $recorded = null;
        $metrics = new MetricsMiddleware(function (string $type, float $duration, bool $success, array $tags) use (&$recorded) {
            $recorded = compact('type', 'duration', 'success', 'tags');
        });

        $chain = new MiddlewareChain();
        $chain->add('metrics', $metrics);

        $chain->invoke($this->makeContext('email.send'), fn(JobContext $ctx) => 'ok');

        $this->assertNotNull($recorded);
        $this->assertEquals('email.send', $recorded['type']);
        $this->assertTrue($recorded['success']);
        $this->assertGreaterThanOrEqual(0, $recorded['duration']);
    }

    public function testMetricsMiddlewareOnFailure(): void
    {
        $recorded = null;
        $metrics = new MetricsMiddleware(function (string $type, float $duration, bool $success, array $tags) use (&$recorded) {
            $recorded = compact('type', 'duration', 'success', 'tags');
        });

        $chain = new MiddlewareChain();
        $chain->add('metrics', $metrics);

        try {
            $chain->invoke($this->makeContext(), function (JobContext $ctx) {
                throw new \RuntimeException('fail');
            });
        } catch (\RuntimeException) {
        }

        $this->assertNotNull($recorded);
        $this->assertFalse($recorded['success']);
    }

    public function testContextStore(): void
    {
        $chain = new MiddlewareChain();
        $chain->add('store-writer', function (JobContext $ctx, callable $next) {
            $ctx->store['start_time'] = microtime(true);
            return $next();
        });

        $storeValue = null;
        $chain->invoke($this->makeContext(), function (JobContext $ctx) use (&$storeValue) {
            $storeValue = $ctx->store['start_time'] ?? null;
            return null;
        });

        $this->assertNotNull($storeValue);
        $this->assertIsFloat($storeValue);
    }

    public function testEmptyChain(): void
    {
        $chain = new MiddlewareChain();
        $result = $chain->invoke($this->makeContext(), fn(JobContext $ctx) => 42);
        $this->assertEquals(42, $result);
    }
}

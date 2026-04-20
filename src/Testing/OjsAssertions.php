<?php

declare(strict_types=1);

namespace OpenJobSpec\Testing;

/**
 * PHPUnit assertion helpers for OJS testing.
 *
 * Usage:
 *   use OpenJobSpec\Testing\OjsAssertions;
 *   class MyTest extends TestCase {
 *       use OjsAssertions;
 *       ...
 *       $this->assertEnqueued($transport, 'email.send');
 *   }
 */
trait OjsAssertions
{
    protected function assertEnqueued(FakeTransport $transport, string $type, ?array $args = null, ?string $queue = null): void
    {
        $found = $transport->allEnqueued($type, $queue);
        $msg = "Expected job type '{$type}' to be enqueued";
        if ($queue !== null) {
            $msg .= " on queue '{$queue}'";
        }

        $this->assertNotEmpty($found, $msg);

        if ($args !== null) {
            $argsMatch = false;
            foreach ($found as $job) {
                if ($job->args === $args) {
                    $argsMatch = true;
                    break;
                }
            }
            $this->assertTrue($argsMatch, "{$msg} with matching args");
        }
    }

    protected function refuteEnqueued(FakeTransport $transport, string $type, ?string $queue = null): void
    {
        $found = $transport->allEnqueued($type, $queue);
        $msg = "Expected job type '{$type}' NOT to be enqueued";
        $this->assertEmpty($found, $msg);
    }

    protected function assertEnqueuedCount(FakeTransport $transport, int $count, ?string $type = null, ?string $queue = null): void
    {
        $actual = $transport->enqueuedCount($type, $queue);
        $msg = "Expected {$count} enqueued jobs";
        if ($type !== null) {
            $msg .= " of type '{$type}'";
        }
        $this->assertEquals($count, $actual, $msg);
    }

    protected function assertCompleted(FakeTransport $transport, string $type): void
    {
        $this->assertTrue($transport->completed($type), "Expected job type '{$type}' to be completed");
    }

    protected function assertFailed(FakeTransport $transport, string $type): void
    {
        $this->assertTrue($transport->failed($type), "Expected job type '{$type}' to be failed");
    }
}

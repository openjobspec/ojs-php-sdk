<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\{CronJob, RetryPolicy};
use PHPUnit\Framework\TestCase;

class CronJobTest extends TestCase
{
    public function testConstructorWithRequiredFields(): void
    {
        $cron = new CronJob(
            name: 'nightly-backup',
            cron: '0 0 * * *',
            type: 'backup.run',
        );

        $this->assertEquals('nightly-backup', $cron->name);
        $this->assertEquals('0 0 * * *', $cron->cron);
        $this->assertEquals('backup.run', $cron->type);
        $this->assertEquals([], $cron->args);
        $this->assertEquals('default', $cron->queue);
        $this->assertEquals([], $cron->meta);
        $this->assertNull($cron->retryPolicy);
        $this->assertNull($cron->priority);
        $this->assertNull($cron->timeout);
    }

    public function testConstructorWithAllFields(): void
    {
        $retry = new RetryPolicy(maxAttempts: 5);
        $cron = new CronJob(
            name: 'daily-cleanup',
            cron: '0 3 * * *',
            type: 'maintenance.cleanup',
            args: ['full', 'verbose'],
            queue: 'maintenance',
            meta: ['env' => 'production'],
            retryPolicy: $retry,
            priority: 10,
            timeout: 300,
        );

        $this->assertEquals('daily-cleanup', $cron->name);
        $this->assertEquals(['full', 'verbose'], $cron->args);
        $this->assertEquals('maintenance', $cron->queue);
        $this->assertEquals(['env' => 'production'], $cron->meta);
        $this->assertSame($retry, $cron->retryPolicy);
        $this->assertEquals(10, $cron->priority);
        $this->assertEquals(300, $cron->timeout);
    }

    public function testToArrayMinimal(): void
    {
        $cron = new CronJob(name: 'test', cron: '* * * * *', type: 'test.job');
        $arr = $cron->toArray();

        $this->assertEquals('test', $arr['name']);
        $this->assertEquals('* * * * *', $arr['cron']);
        $this->assertEquals('test.job', $arr['type']);
        $this->assertEquals([], $arr['args']);
        $this->assertEquals('default', $arr['queue']);
        $this->assertArrayNotHasKey('meta', $arr);
        $this->assertArrayNotHasKey('retry', $arr);
        $this->assertArrayNotHasKey('priority', $arr);
        $this->assertArrayNotHasKey('timeout', $arr);
    }

    public function testToArrayWithOptionalFields(): void
    {
        $retry = new RetryPolicy(maxAttempts: 3);
        $cron = new CronJob(
            name: 'full-job',
            cron: '*/5 * * * *',
            type: 'data.sync',
            args: ['region' => 'us-east'],
            queue: 'sync',
            meta: ['source' => 'cron'],
            retryPolicy: $retry,
            priority: 5,
            timeout: 120,
        );

        $arr = $cron->toArray();

        $this->assertEquals(['source' => 'cron'], $arr['meta']);
        $this->assertArrayHasKey('retry', $arr);
        $this->assertEquals(3, $arr['retry']['max_attempts']);
        $this->assertEquals(5, $arr['priority']);
        $this->assertEquals(120, $arr['timeout']);
    }

    public function testToArrayOmitsEmptyMeta(): void
    {
        $cron = new CronJob(name: 'a', cron: '* * * * *', type: 'b', meta: []);
        $this->assertArrayNotHasKey('meta', $cron->toArray());
    }

    public function testFromArrayWithMinimalData(): void
    {
        $cron = CronJob::fromArray([
            'name' => 'hourly-sync',
            'cron' => '0 * * * *',
            'type' => 'sync.run',
        ]);

        $this->assertEquals('hourly-sync', $cron->name);
        $this->assertEquals('0 * * * *', $cron->cron);
        $this->assertEquals('sync.run', $cron->type);
        $this->assertEquals([], $cron->args);
        $this->assertEquals('default', $cron->queue);
        $this->assertNull($cron->retryPolicy);
        $this->assertNull($cron->priority);
        $this->assertNull($cron->timeout);
    }

    public function testFromArrayWithAllFields(): void
    {
        $cron = CronJob::fromArray([
            'name' => 'full',
            'cron' => '0 0 1 * *',
            'type' => 'report.generate',
            'args' => ['monthly'],
            'queue' => 'reports',
            'meta' => ['team' => 'analytics'],
            'retry' => ['max_attempts' => 7, 'initial_interval' => 'PT10S'],
            'priority' => 3,
            'timeout' => 600,
        ]);

        $this->assertEquals('full', $cron->name);
        $this->assertEquals(['monthly'], $cron->args);
        $this->assertEquals('reports', $cron->queue);
        $this->assertEquals(['team' => 'analytics'], $cron->meta);
        $this->assertNotNull($cron->retryPolicy);
        $this->assertEquals(7, $cron->retryPolicy->maxAttempts);
        $this->assertEquals('PT10S', $cron->retryPolicy->initialInterval);
        $this->assertEquals(3, $cron->priority);
        $this->assertEquals(600, $cron->timeout);
    }

    public function testFromArrayEmptyDefaults(): void
    {
        $cron = CronJob::fromArray([]);

        $this->assertEquals('', $cron->name);
        $this->assertEquals('', $cron->cron);
        $this->assertEquals('', $cron->type);
        $this->assertEquals([], $cron->args);
        $this->assertEquals('default', $cron->queue);
    }

    public function testSerializationRoundtrip(): void
    {
        $original = new CronJob(
            name: 'roundtrip',
            cron: '30 2 * * 1-5',
            type: 'weekday.task',
            args: [1, 2, 3],
            queue: 'critical',
            meta: ['k' => 'v'],
            retryPolicy: new RetryPolicy(maxAttempts: 2, initialInterval: '5s'),
            priority: 8,
            timeout: 60,
        );

        $restored = CronJob::fromArray($original->toArray());

        $this->assertEquals($original->name, $restored->name);
        $this->assertEquals($original->cron, $restored->cron);
        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->args, $restored->args);
        $this->assertEquals($original->queue, $restored->queue);
        $this->assertEquals($original->meta, $restored->meta);
        $this->assertEquals($original->priority, $restored->priority);
        $this->assertEquals($original->timeout, $restored->timeout);
        $this->assertNotNull($restored->retryPolicy);
        $this->assertEquals(2, $restored->retryPolicy->maxAttempts);
    }
}

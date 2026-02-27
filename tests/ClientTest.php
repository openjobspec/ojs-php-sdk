<?php

declare(strict_types=1);

namespace OpenJobSpec\Tests;

use OpenJobSpec\Client;
use OpenJobSpec\Job;
use OpenJobSpec\Workflow;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testJobFromArray(): void
    {
        $job = Job::fromArray([
            'id' => 'test-123',
            'type' => 'email.send',
            'args' => ['user@example.com'],
            'queue' => 'emails',
            'state' => 'available',
        ]);

        $this->assertEquals('test-123', $job->id);
        $this->assertEquals('email.send', $job->type);
        $this->assertEquals(['user@example.com'], $job->args);
        $this->assertEquals('emails', $job->queue);
        $this->assertEquals('available', $job->state);
    }

    public function testWorkflowChain(): void
    {
        $workflow = Workflow::chain('test-chain', [
            ['type' => 'step.one', 'args' => [1]],
            ['type' => 'step.two', 'args' => [2]],
        ]);

        $this->assertEquals('chain', $workflow['type']);
        $this->assertEquals('test-chain', $workflow['name']);
        $this->assertCount(2, $workflow['steps']);
    }

    public function testWorkflowGroup(): void
    {
        $workflow = Workflow::group('test-group', [
            ['type' => 'task.a', 'args' => []],
            ['type' => 'task.b', 'args' => []],
        ]);

        $this->assertEquals('group', $workflow['type']);
        $this->assertCount(2, $workflow['jobs']);
    }
}

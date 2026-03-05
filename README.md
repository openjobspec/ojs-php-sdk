# OJS PHP SDK

PHP 8.2+ SDK for [Open Job Spec](https://openjobspec.org) — the open standard for background job processing.

> **Zero required dependencies** — uses only `ext-curl` and `ext-json` from PHP stdlib.

## Installation

```bash
composer require openjobspec/sdk
```

## Quick Start

```php
use OpenJobSpec\Client;
use OpenJobSpec\Worker;
use OpenJobSpec\JobContext;

// Enqueue a job
$client = new Client('http://localhost:8080');
$job = $client->enqueue('email.send', ['user@example.com', 'Welcome!']);

// Process jobs
$worker = new Worker('http://localhost:8080');
$worker->register('email.send', function (JobContext $ctx) {
    sendEmail($ctx->job->args[0], $ctx->job->args[1]);
    return ['status' => 'sent'];
});
$worker->start();
```

## Features

| Feature | Status |
|---------|--------|
| Client (enqueue) | ✅ |
| Batch enqueue | ✅ |
| Worker (register/start) | ✅ |
| Middleware chain | ✅ |
| Workflows (chain/group/batch) | ✅ |
| Retry policy | ✅ |
| Unique jobs | ✅ |
| Events | ✅ |
| Queue management | ✅ |
| Cron scheduling | ✅ |
| Dead letter management | ✅ |
| Schema validation | ✅ |
| OpenTelemetry | ✅ |
| Testing utilities | ✅ |
| SSE subscribe | ✅ |

## Client

```php
use OpenJobSpec\{Client, RetryPolicy, UniquePolicy, CronJob, Workflow, Step};

$client = new Client('http://localhost:8080', [
    'auth_token' => 'my-token',  // optional
    'timeout' => 30,             // HTTP timeout in seconds
]);

// Enqueue with options
$job = $client->enqueue('email.send', ['user@example.com'], [
    'queue' => 'emails',
    'priority' => 10,
    'scheduled_at' => '2025-01-01T09:00:00Z',
    'retry' => new RetryPolicy(maxAttempts: 5, initialInterval: 'PT30S'),
    'unique' => new UniquePolicy(keys: ['type', 'args'], period: 'PT1H'),
    'meta' => ['user_id' => 'u123', 'trace_id' => 'abc'],
]);

// Batch enqueue
$jobs = $client->enqueueBatch([
    ['type' => 'email.send', 'args' => ['a@b.com']],
    ['type' => 'email.send', 'args' => ['c@d.com']],
]);

// Job operations
$job = $client->getJob('job-id');
$client->cancel('job-id');

// Queue management
$queues = $client->getQueues();
$stats = $client->getQueueStats('emails');
$client->pauseQueue('emails');
$client->resumeQueue('emails');

// Dead letter
$deadJobs = $client->getDeadLetterJobs('emails');
$client->retryDeadLetter('job-id');
$client->discardDeadLetter('job-id');

// Cron jobs
$client->registerCronJob(new CronJob(
    name: 'daily-report',
    cron: '0 9 * * *',
    type: 'report.generate',
    args: ['daily'],
));
$cronJobs = $client->listCronJobs();
$client->unregisterCronJob('daily-report');

// Workflows
$client->workflow(Workflow::chain('etl-pipeline', [
    new Step(type: 'data.fetch', args: ['url' => 'https://api.example.com']),
    new Step(type: 'data.transform', args: ['format' => 'csv']),
    new Step(type: 'data.store', args: ['bucket' => 's3://output']),
]));

// Health & manifest
$health = $client->health();
$manifest = $client->manifest();
```

## Worker

```php
use OpenJobSpec\{Worker, JobContext, LoggingMiddleware, MetricsMiddleware};

$worker = new Worker('http://localhost:8080', [
    'queues' => ['emails', 'reports'],
    'concurrency' => 10,
    'poll_interval' => 2.0,
    'heartbeat_interval' => 15.0,
    'shutdown_timeout' => 25.0,
]);

// Register handlers
$worker->register('email.send', function (JobContext $ctx) {
    $email = $ctx->job->args[0];
    $subject = $ctx->job->args[1];
    // ... send email
    return ['status' => 'sent'];
});

// Add middleware (outermost first)
$worker->use('logging', new LoggingMiddleware());
$worker->use('metrics', new MetricsMiddleware(function ($type, $duration, $success, $tags) {
    // Record to StatsD, Prometheus, etc.
}));

// Custom middleware via closure
$worker->use('auth', function (JobContext $ctx, callable $next) {
    $ctx->store['tenant'] = $ctx->job->meta['tenant_id'] ?? 'default';
    return $next();
});

// Event listeners
$worker->on('job.completed', function ($event) {
    echo "Job completed: {$event->data['job_id']}\n";
});

// Start (blocks until SIGTERM/SIGINT)
$worker->start();
```

### Middleware Chain

Named middleware with positional control:

```php
$chain = $worker->middlewareChain();
$chain->add('logging', new LoggingMiddleware());
$chain->prepend('auth', $authMiddleware);           // Add to front
$chain->insertBefore('logging', 'metrics', $metricsMiddleware);
$chain->insertAfter('auth', 'rate-limit', $rateLimitMiddleware);
$chain->remove('logging');
$chain->has('auth');  // true
```

## Workflows

```php
use OpenJobSpec\{Workflow, Step};

// Chain (sequential)
$chain = Workflow::chain('etl', [
    new Step(type: 'extract', args: ['source' => 'db']),
    new Step(type: 'transform', args: ['format' => 'csv']),
    new Step(type: 'load', args: ['dest' => 's3']),
]);

// Group (parallel)
$group = Workflow::group('multi-export', [
    new Step(type: 'export.csv', args: ['id' => 1]),
    new Step(type: 'export.pdf', args: ['id' => 1]),
]);

// Batch (parallel with callbacks)
$batch = Workflow::batch(
    'email-blast',
    [
        new Step(type: 'email.send', args: ['to' => 'a@b.com']),
        new Step(type: 'email.send', args: ['to' => 'c@d.com']),
    ],
    onComplete: new Step(type: 'blast.report', args: []),
    onFailure: new Step(type: 'blast.alert', args: []),
);
```

## Schema Validation

```php
use OpenJobSpec\{SchemaValidator, SchemaValidationMiddleware};

$validator = new SchemaValidator();
$validator->register('urn:ojs:email.send:v1', [
    'type' => 'array',
    'items' => ['type' => 'string'],
]);

// Use as middleware
$worker->use('schema', new SchemaValidationMiddleware($validator));
```

## SSE Subscriptions

```php
use OpenJobSpec\SSESubscription;

// Subscribe to job events
$sub = SSESubscription::forJob('http://localhost:8080', $jobId, function ($event) {
    echo "Event: {$event->type}\n";
});

// Subscribe to queue events
$sub = SSESubscription::forQueue('http://localhost:8080', 'emails', function ($event) {
    echo "Queue event: {$event->type}\n";
});

$sub->cancel(); // Stop receiving events
```

## Testing

The SDK includes a fake transport and PHPUnit assertion helpers:

```php
use OpenJobSpec\{Client, Worker, JobContext};
use OpenJobSpec\Testing\{FakeTransport, OjsAssertions};
use PHPUnit\Framework\TestCase;

class MyJobTest extends TestCase
{
    use OjsAssertions;

    public function testEmailJobIsEnqueued(): void
    {
        $transport = new FakeTransport();
        $client = new Client('http://fake', ['transport' => $transport]);

        // Your application code
        $client->enqueue('email.send', ['user@example.com']);

        // Assert
        $this->assertEnqueued($transport, 'email.send', ['user@example.com']);
        $this->assertEnqueuedCount($transport, 1, 'email.send');
        $this->refuteEnqueued($transport, 'sms.send');
    }

    public function testEmailJobProcessing(): void
    {
        $transport = new FakeTransport();
        $client = new Client('http://fake', ['transport' => $transport]);

        $client->enqueue('email.send', ['user@example.com']);

        // Drain processes all available jobs
        $transport->drain([
            'email.send' => function (JobContext $ctx) {
                return ['status' => 'sent'];
            },
        ]);

        $this->assertCompleted($transport, 'email.send');
    }
}
```

## Error Handling

The SDK provides a typed error hierarchy:

```php
use OpenJobSpec\{
    OjsException,       // Base class
    ConnectionError,    // Network issues (retryable)
    TimeoutError,       // Request timeout (retryable)
    ValidationError,    // Invalid input (not retryable)
    NotFoundError,      // Resource not found
    ConflictError,      // Unique constraint violation
    RateLimitError,     // Rate limited (retryable, has retryAfter)
    ServerError,        // Server error (retryable)
    PayloadTooLargeError,
};

try {
    $client->enqueue('job.type', $args);
} catch (RateLimitError $e) {
    sleep($e->retryAfter ?? 1);
    // retry...
} catch (ValidationError $e) {
    // Fix input, don't retry
} catch (OjsException $e) {
    if ($e->retryable) {
        // Safe to retry
    }
}
```

## Requirements

- PHP 8.2+
- `ext-curl`
- `ext-json`

## Development

```bash
composer install
composer test          # Run PHPUnit tests
```

## License

Apache-2.0

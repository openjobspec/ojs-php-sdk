# OJS PHP SDK

PHP 8.2+ SDK for [Open Job Spec](https://openjobspec.org) — the open standard for background job processing.

## Installation

```bash
composer require openjobspec/sdk
```

## Quick Start

```php
use OpenJobSpec\Client;
use OpenJobSpec\Worker;

// Enqueue a job
$client = new Client('http://localhost:8080');
$job = $client->enqueue('email.send', ['user@example.com', 'Welcome!']);

// Process jobs
$worker = new Worker('http://localhost:8080');
$worker->register('email.send', function ($job) {
    sendEmail($job->args[0], $job->args[1]);
});
$worker->start();
```

## License

Apache-2.0

<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Job context passed through the middleware chain and to handlers.
 *
 * Provides access to the job, a mutable key-value store for sharing
 * data between middleware, and a heartbeat method to extend visibility.
 */
class JobContext
{
    public array $store = [];

    public function __construct(
        public readonly Job $job,
        private readonly ?Transport $transport = null,
    ) {}

    /**
     * Send a heartbeat to extend the job's visibility timeout.
     */
    public function heartbeat(): void
    {
        $this->transport?->post("/ojs/v1/workers/heartbeat", [
            'job_id' => $this->job->id,
        ]);
    }
}

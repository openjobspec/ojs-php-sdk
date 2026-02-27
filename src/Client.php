<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * OJS Client - enqueue jobs to an OJS-compliant backend.
 */
class Client
{
    private string $baseUrl;
    private array $headers;

    public function __construct(string $url, array $options = [])
    {
        $this->baseUrl = rtrim($url, '/');
        $this->headers = [
            'Content-Type' => 'application/openjobspec+json',
            'Accept' => 'application/openjobspec+json',
        ];
        if (isset($options['auth_token'])) {
            $this->headers['Authorization'] = 'Bearer ' . $options['auth_token'];
        }
    }

    public function enqueue(string $type, array $args = [], array $options = []): Job
    {
        $body = ['type' => $type, 'args' => $args];
        if (!empty($options)) {
            $body['options'] = $options;
        }
        $response = $this->post('/ojs/v1/jobs', $body);
        return Job::fromArray($response);
    }

    public function getJob(string $jobId): Job
    {
        $response = $this->get("/ojs/v1/jobs/{$jobId}");
        return Job::fromArray($response);
    }

    public function cancel(string $jobId): Job
    {
        $response = $this->post("/ojs/v1/jobs/{$jobId}/cancel", []);
        return Job::fromArray($response);
    }

    private function post(string $path, array $body): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders(),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?: [];
    }

    private function get(string $path): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaders(),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?: [];
    }

    private function formatHeaders(): array
    {
        $result = [];
        foreach ($this->headers as $k => $v) {
            $result[] = "{$k}: {$v}";
        }
        return $result;
    }
}

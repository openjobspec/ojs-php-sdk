<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * HTTP transport abstraction for OJS API communication.
 *
 * Implementations handle the actual HTTP calls, enabling easy testing
 * via fake transports and customization for different HTTP clients.
 */
interface Transport
{
    /**
     * Send a POST request and return the decoded response body.
     *
     * @throws OjsException On transport or server errors
     */
    public function post(string $path, array $body = []): array;

    /**
     * Send a GET request and return the decoded response body.
     *
     * @throws OjsException On transport or server errors
     */
    public function get(string $path, array $query = []): array;

    /**
     * Send a DELETE request and return the decoded response body.
     *
     * @throws OjsException On transport or server errors
     */
    public function delete(string $path): array;
}

/**
 * cURL-based HTTP transport for production use.
 */
class HttpTransport implements Transport
{
    private string $baseUrl;
    private array $headers;
    private int $connectTimeout;
    private int $timeout;

    private int $maxRetries;
    private bool $retryServerErrors;

    public function __construct(string $baseUrl, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->connectTimeout = $options['connect_timeout'] ?? 5;
        $this->timeout = $options['timeout'] ?? 30;
        $this->maxRetries = $options['max_retries'] ?? 3;
        $this->retryServerErrors = $options['retry_server_errors'] ?? true;
        $this->headers = [
            'Content-Type' => 'application/openjobspec+json',
            'Accept' => 'application/openjobspec+json',
            'OJS-Version' => '1.0',
        ];
        if (isset($options['auth_token'])) {
            $this->headers['Authorization'] = 'Bearer ' . $options['auth_token'];
        }
        foreach ($options['headers'] ?? [] as $key => $value) {
            $this->headers[$key] = $value;
        }
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    public function get(string $path, array $query = []): array
    {
        $url = $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->requestWithRetry($method, $path, $body, 0);
    }

    private function requestWithRetry(string $method, string $path, ?array $body, int $attempt): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) {
            throw new ConnectionError("Failed to initialize cURL for {$method} {$path}");
        }

        $requestHeaders = $this->headers;
        $requestHeaders['X-Request-ID'] = $this->generateRequestId();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->formatHeaderArray($requestHeaders),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            if ($curlErrno === CURLE_OPERATION_TIMEDOUT || $curlErrno === CURLE_OPERATION_TIMEOUTED) {
                throw new TimeoutError("Request timed out: {$curlError}");
            }
            if ($curlErrno === CURLE_COULDNT_CONNECT || $curlErrno === CURLE_COULDNT_RESOLVE_HOST || $curlErrno === CURLE_COULDNT_RESOLVE_PROXY) {
                throw new ConnectionError("Failed to connect to OJS server: {$curlError}");
            }
            throw new ConnectionError("cURL error ({$curlErrno}): {$curlError}");
        }

        if (!is_string($response) || $response === '') {
            if ($httpCode >= 200 && $httpCode < 300) {
                return [];
            }
            throw new ServerError("Empty response with HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new ServerError("Invalid JSON response: " . json_last_error_msg());
        }

        if ($httpCode === 429 || ($this->retryServerErrors && in_array($httpCode, [502, 503, 504], true))) {
            if ($attempt < $this->maxRetries) {
                $delay = min(0.5 * pow(2, $attempt), 30.0) * (0.5 + lcg_value() * 0.5);
                usleep((int) ($delay * 1_000_000));
                return $this->requestWithRetry($method, $path, $body, $attempt + 1);
            }
        }

        if ($httpCode >= 400) {
            throw OjsException::fromResponse($decoded ?? [], $httpCode);
        }

        return $decoded ?? [];
    }

    private function formatHeaders(): array
    {
        return $this->formatHeaderArray($this->headers);
    }

    /** @param array<string, string> $headers */
    private function formatHeaderArray(array $headers): array
    {
        $result = [];
        foreach ($headers as $k => $v) {
            $result[] = "{$k}: {$v}";
        }
        return $result;
    }

    private function generateRequestId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

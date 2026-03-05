<?php

declare(strict_types=1);

namespace OpenJobSpec;

/**
 * Base exception for all OJS SDK errors.
 */
class OjsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $code = null,
        public readonly bool $retryable = false,
        public readonly ?string $requestId = null,
        public readonly ?int $httpStatus = null,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(array $body, int $httpStatus = 500): self
    {
        $code = $body['code'] ?? $body['error']['type'] ?? null;
        $message = $body['message'] ?? $body['error']['message'] ?? 'Unknown OJS error';
        $requestId = $body['request_id'] ?? null;
        $details = $body['details'] ?? [];

        return match (true) {
            $httpStatus === 404 => new NotFoundError($message, $code, $requestId, $details),
            $httpStatus === 409 => new ConflictError($message, $code, $requestId, $details),
            $httpStatus === 413 => new PayloadTooLargeError($message, $code, $requestId, $details),
            $httpStatus === 422 => new ValidationError($message, $code, $requestId, $details),
            $httpStatus === 429 => new RateLimitError(
                $message, $code, $requestId, $details,
                retryAfter: isset($body['retry_after']) ? (int) $body['retry_after'] : null,
            ),
            $httpStatus === 503 => new QueuePausedError($message, $code, $requestId, $details),
            $httpStatus >= 500 => new ServerError($message, $code, $requestId, $details),
            default => new self($message, $code, false, $requestId, $httpStatus, $details),
        };
    }
}

class ConnectionError extends OjsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'connection_error', true, previous: $previous);
    }
}

class TimeoutError extends OjsException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 'timeout', true, previous: $previous);
    }
}

class ValidationError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'validation_error', false, $requestId, 422, $details);
    }
}

class NotFoundError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'not_found', false, $requestId, 404, $details);
    }
}

class ConflictError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'conflict', false, $requestId, 409, $details);
    }
}

class QueuePausedError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'queue_paused', true, $requestId, 503, $details);
    }
}

class RateLimitError extends OjsException
{
    public function __construct(
        string $message,
        ?string $code = null,
        ?string $requestId = null,
        array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $code ?? 'rate_limited', true, $requestId, 429, $details);
    }
}

class ServerError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'server_error', true, $requestId, 500, $details);
    }
}

class PayloadTooLargeError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'payload_too_large', false, $requestId, 413, $details);
    }
}

class UnsupportedError extends OjsException
{
    public function __construct(string $message, ?string $code = null, ?string $requestId = null, array $details = [])
    {
        parent::__construct($message, $code ?? 'unsupported', false, $requestId, 501, $details);
    }
}

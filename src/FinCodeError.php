<?php

declare(strict_types=1);

namespace FinCode;

use RuntimeException;

/**
 * Raised for API errors, timeouts and network failures.
 */
final class FinCodeError extends RuntimeException
{
    /**
     * @param string      $message    Human readable message from the API.
     * @param string      $errorCode  Machine readable code, e.g. `INVALID_IBAN`.
     * @param int         $status     HTTP status, or 0 for timeouts and network failures.
     * @param string|null $requestId  Server request id, quote it when contacting support.
     * @param float|null  $retryAfter Seconds from the `Retry-After` header, when present.
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 0,
        public readonly ?string $requestId = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    /**
     * True for transient failures that may succeed when retried as-is.
     */
    public function isRetryable(): bool
    {
        return $this->status === 0 || in_array($this->status, Client::RETRYABLE_STATUSES, true);
    }
}

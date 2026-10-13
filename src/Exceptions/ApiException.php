<?php

declare(strict_types=1);

namespace Satlane\Exceptions;

/**
 * Thrown when the API returns a non-2xx response.
 *
 * Carries the SatLane error code, HTTP status, and the server-side
 * request id so support can correlate to logs.
 *
 * Inspect specific cases with the static helpers:
 *
 *   try {
 *       $client->invoices->create([...]);
 *   } catch (\Satlane\Exceptions\ApiException $e) {
 *       if ($e->isRetryable()) { ... }
 *       if ($e->code() === 'gap_limit_exceeded') { ... }
 *   }
 */
class ApiException extends SatlaneException
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $errorCode,
        public readonly ?string $requestId,
        public readonly ?array $details,
        string $message,
    ) {
        parent::__construct($message, $status);
    }

    public function code(): ?string
    {
        return $this->errorCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function details(): ?array
    {
        return $this->details;
    }

    /**
     * True for transient infrastructure conditions (chain syncing, disk
     * full, DB blip, rate limited) where exponential backoff + retry is
     * appropriate. False for client-side bugs (4xx) that won't resolve
     * by retrying.
     */
    public function isRetryable(): bool
    {
        if ($this->status === 0) {
            return true;
        }
        if ($this->status === 429) {
            return true;
        }
        if ($this->status >= 500) {
            return true;
        }
        return false;
    }
}

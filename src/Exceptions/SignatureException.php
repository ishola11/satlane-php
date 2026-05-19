<?php

declare(strict_types=1);

namespace Satlane\Exceptions;

/**
 * Thrown when a webhook signature fails to verify.
 *
 * Subclasses convey the exact reason so your handler can decide whether
 * to log + reject (the only correct response) or take a different
 * action while still rejecting.
 */
class SignatureException extends SatlaneException
{
    public const MISSING_HEADER = 'missing_header';
    public const MALFORMED_HEADER = 'malformed_header';
    public const TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';
    public const SIGNATURE_MISMATCH = 'signature_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}

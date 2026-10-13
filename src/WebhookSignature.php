<?php

declare(strict_types=1);

namespace Satlane;

use Satlane\Exceptions\SignatureException;

/**
 * HMAC-SHA256 signature helpers for SatLane webhooks.
 *
 * Wire format (Stripe-style):
 *
 *   X-SatLane-Signature: t=<unix_seconds>,v1=<hex_hmac>
 *
 * Signed payload: `${timestamp}.${raw_body}`. The signature is HMAC-SHA256
 * over that string with the endpoint secret as the key, hex-encoded.
 *
 * Usage in a controller:
 *
 *   $raw = file_get_contents('php://input');
 *   $header = $_SERVER['HTTP_X_SATLANE_SIGNATURE'] ?? '';
 *
 *   try {
 *       WebhookSignature::verify($raw, $header, $secret);
 *   } catch (SignatureException $e) {
 *       http_response_code(400);
 *       exit;
 *   }
 *
 *   $event = json_decode($raw, true);
 *
 * Always verify against the RAW request body. Body parsers that hand you
 * an array have already dropped the bytes you need.
 */
final class WebhookSignature
{
    public const DEFAULT_TOLERANCE_SECONDS = 300; // 5 min

    /**
     * Verify a SatLane webhook signature.
     *
     * @param string|array<string> $secrets One secret, or an array of
     *   secrets to try in order. Pass `[$current, $previous]` during
     *   a rotation so the previous secret still validates for the
     *   24h grace period.
     *
     * @throws SignatureException
     */
    public static function verify(
        string $rawBody,
        string $signatureHeader,
        string|array $secrets,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE_SECONDS,
    ): void {
        if ($signatureHeader === '') {
            throw new SignatureException(
                SignatureException::MISSING_HEADER,
                'X-SatLane-Signature header is missing.',
            );
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $piece) {
            if (!str_contains($piece, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $piece, 2);
            $k = trim($k);
            if (isset($parts[$k])) {
                throw new SignatureException(SignatureException::MALFORMED_HEADER, 'Duplicate signature header field.');
            }
            $parts[$k] = trim($v);
        }

        if (!isset($parts['t']) || !isset($parts['v1']) || !preg_match('/^[0-9]{1,12}$/D', $parts['t']) || !preg_match('/^[a-f0-9]{64}$/D', $parts['v1']) || $toleranceSeconds < 0) {
            throw new SignatureException(
                SignatureException::MALFORMED_HEADER,
                'X-SatLane-Signature must contain t=<ts>,v1=<hex>.',
            );
        }

        $timestamp = (int) $parts['t'];
        $providedSig = $parts['v1'];

        if ($timestamp <= 0) {
            throw new SignatureException(
                SignatureException::MALFORMED_HEADER,
                'X-SatLane-Signature timestamp is invalid.',
            );
        }

        $age = abs(time() - $timestamp);
        if ($age > $toleranceSeconds) {
            throw new SignatureException(
                SignatureException::TIMESTAMP_OUT_OF_TOLERANCE,
                "Signature timestamp is {$age}s old (max {$toleranceSeconds}s). " .
                'Possible replay.',
            );
        }

        $signedPayload = $timestamp . '.' . $rawBody;
        $secretList = is_array($secrets) ? $secrets : [$secrets];

        foreach ($secretList as $secret) {
            $expected = hash_hmac('sha256', $signedPayload, $secret);
            if (hash_equals($expected, $providedSig)) {
                return;
            }
        }

        throw new SignatureException(
            SignatureException::SIGNATURE_MISMATCH,
            'Webhook signature did not match. Wrong secret or tampered body.',
        );
    }

    /**
     * Helper for tests / local mocking. Generates a valid signature
     * header for the given body and secret. NOT used in production
     * code paths.
     */
    public static function sign(
        string $rawBody,
        string $secret,
        ?int $timestamp = null,
    ): string {
        $timestamp ??= time();
        $sig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        return "t={$timestamp},v1={$sig}";
    }
}

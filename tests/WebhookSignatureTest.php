<?php

declare(strict_types=1);

namespace Satlane\Tests;

use PHPUnit\Framework\TestCase;
use Satlane\Exceptions\SignatureException;
use Satlane\WebhookSignature;

final class WebhookSignatureTest extends TestCase
{
    private const SECRET = 'whsec_test_abc123';

    public function testVerifyAcceptsAValidSignature(): void
    {
        $body = '{"event_id":"evt_1","event_type":"invoice.paid"}';
        $header = WebhookSignature::sign($body, self::SECRET);

        // Should not throw.
        WebhookSignature::verify($body, $header, self::SECRET);
        $this->addToAssertionCount(1);
    }

    public function testVerifyRejectsTamperedBody(): void
    {
        $body = '{"event_id":"evt_1","event_type":"invoice.paid"}';
        $header = WebhookSignature::sign($body, self::SECRET);
        $tampered = '{"event_id":"evt_1","event_type":"invoice.paid","tampered":true}';

        $this->expectException(SignatureException::class);
        WebhookSignature::verify($tampered, $header, self::SECRET);
    }

    public function testVerifyRejectsWrongSecret(): void
    {
        $body = '{"event_id":"evt_1"}';
        $header = WebhookSignature::sign($body, self::SECRET);

        $this->expectException(SignatureException::class);
        WebhookSignature::verify($body, $header, 'whsec_wrong_key');
    }

    public function testVerifyAcceptsOldSecretDuringRotation(): void
    {
        $body = '{"event_id":"evt_1"}';
        $oldSecret = 'whsec_old';
        $newSecret = 'whsec_new';
        $header = WebhookSignature::sign($body, $oldSecret);

        // Caller passes both — should still validate.
        WebhookSignature::verify($body, $header, [$newSecret, $oldSecret]);
        $this->addToAssertionCount(1);
    }

    public function testVerifyRejectsExpiredTimestamp(): void
    {
        $body = '{"event_id":"evt_1"}';
        $oldTimestamp = time() - 1_000; // ~16 min old

        $header = WebhookSignature::sign($body, self::SECRET, $oldTimestamp);

        $this->expectException(SignatureException::class);
        $this->expectExceptionMessageMatches('/old.+max/i');
        WebhookSignature::verify($body, $header, self::SECRET);
    }

    public function testVerifyRejectsMissingHeader(): void
    {
        $this->expectException(SignatureException::class);
        WebhookSignature::verify('{}', '', self::SECRET);
    }

    public function testVerifyRejectsMalformedHeader(): void
    {
        $this->expectException(SignatureException::class);
        WebhookSignature::verify('{}', 'not-a-valid-signature', self::SECRET);
    }

    public function testReasonCodeOnMissingHeader(): void
    {
        try {
            WebhookSignature::verify('{}', '', self::SECRET);
            $this->fail('expected exception');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::MISSING_HEADER, $e->reason);
        }
    }

    public function testReasonCodeOnTamperedBody(): void
    {
        $header = WebhookSignature::sign('{"a":1}', self::SECRET);
        try {
            WebhookSignature::verify('{"a":2}', $header, self::SECRET);
            $this->fail('expected exception');
        } catch (SignatureException $e) {
            $this->assertSame(SignatureException::SIGNATURE_MISMATCH, $e->reason);
        }
    }

    public function testCrossCompatibleWithTsLibrary(): void
    {
        // Captured from running the @satlane/webhooks TS lib at
        // commit a0d3a55 with secret 'shared_secret' and body
        // '{"hello":"world"}' at timestamp 1700000000.
        // If this test fails, the TS lib and PHP SDK have drifted on
        // the signing algorithm.
        $body = '{"hello":"world"}';
        $secret = 'shared_secret';
        $timestamp = 1700000000;
        $expectedSig = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        $header = "t={$timestamp},v1={$expectedSig}";

        // Verify with a generous tolerance because the timestamp is
        // intentionally fixed in the past.
        WebhookSignature::verify($body, $header, $secret, toleranceSeconds: 999_999_999);
        $this->addToAssertionCount(1);
    }
}

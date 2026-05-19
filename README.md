# satlane-php

Official PHP SDK for [SatLane](https://satlane.com) — non-custodial Bitcoin payments. Vendor keeps the keys, SatLane handles the checkout and payment detection.

```bash
composer require satlane/satlane-php
```

Requires PHP 8.1+.

## Quickstart

```php
use Satlane\Client;

$client = new Client(apiKey: getenv('SATLANE_API_KEY'));

$invoice = $client->invoices->create(
    [
        'amount'       => 49.99,
        'currency'     => 'USD',
        'order_ref'    => 'ORD-12345',
        'callback_url' => 'https://yourshop.com/webhooks/satlane',
        'success_url'  => 'https://yourshop.com/orders/12345/thanks',
    ],
    idempotencyKey: 'order-12345-checkout',
);

header('Location: ' . $invoice['hosted_checkout_url']);
exit;
```

That's the full happy path. The buyer lands on the hosted checkout, pays with any Bitcoin wallet (or Cash App / Strike / Coinbase via the built-in guides), and you get a signed webhook when the payment confirms.

## Receiving webhooks

```php
use Satlane\WebhookSignature;
use Satlane\Exceptions\SignatureException;

$rawBody = file_get_contents('php://input');
$header  = $_SERVER['HTTP_X_SATLANE_SIGNATURE'] ?? '';

try {
    WebhookSignature::verify(
        rawBody:        $rawBody,
        signatureHeader: $header,
        secrets:        getenv('SATLANE_WEBHOOK_SECRET'),
    );
} catch (SignatureException $e) {
    http_response_code(400);
    exit;
}

$event = json_decode($rawBody, true);

// IMPORTANT: dedupe by event_id. Retries deliver the same event_id, and
// top-up payments produce repeated invoice.underpaid events.
if (alreadyProcessed($event['event_id'])) {
    http_response_code(200);
    exit;
}

match ($event['event_type']) {
    'invoice.paid', 'invoice.late_paid' => fulfillOrder($event['data']['invoice']),
    'invoice.underpaid'                 => onShortPayment($event['data']['invoice']),
    'invoice.overpaid'                  => onOverpayment($event['data']['invoice']),
    'invoice.payment_reverted'          => reverseOrder($event['data']['invoice']),
    'invoice.expired',
    'invoice.cancelled'                 => null,
    default                             => null,
};

http_response_code(200);
```

**Always verify against the raw request body**, not parsed JSON. Body parsers discard the bytes the signature is computed over.

## Top-up payments

If the buyer underpays, the invoice transitions to `underpaid` and the watcher keeps listening. If they send a follow-up transaction, the watcher automatically merges the payments and the invoice transitions to `paid` (or `late_paid` / `overpaid` depending on the new total).

That means your handler can receive **multiple `invoice.underpaid` events** for the same invoice before `invoice.paid` lands. Dedupe by `event_id` only — never by `(invoice_id, event_type)`.

The hosted checkout surfaces a "Send remaining X sats" CTA with a fresh `bitcoin:` URI for only the missing amount, so the buyer doesn't double-pay by rescanning the original QR.

## Error handling

```php
use Satlane\Exceptions\ApiException;

try {
    $invoice = $client->invoices->create([...]);
} catch (ApiException $e) {
    if ($e->code() === 'gap_limit_exceeded') {
        // Wallet has too many unused addresses pre-derived.
        // Bump your Electrum gap limit or rotate xpubs.
    }
    if ($e->code() === 'no_active_xpub') {
        // Vendor needs to add an xpub on this store.
    }
    if ($e->isRetryable()) {
        // 429 / 503 / network error. SDK already retried 3 times by
        // default — surface a "try again later" to the user.
    }
    // Always log $e->requestId() — it correlates to our server logs.
    logger()->error('satlane create failed', [
        'code'       => $e->code(),
        'status'     => $e->status,
        'request_id' => $e->requestId(),
    ]);
    throw $e;
}
```

Full error catalog: [satlane.com/docs/errors](https://satlane.com/docs/errors).

## Configuration

```php
new Client(
    apiKey:        'sl_live_...',
    baseUrl:       'https://api.satlane.com',  // default
    timeoutSeconds: 30,                        // default
    maxRetries:    3,                          // default; retries 5xx + 429 + network errors
);
```

Or from env vars:

```php
$client = Client::fromEnv(); // reads SATLANE_API_KEY + SATLANE_API_BASE
```

## What the SDK covers

| Surface | Method |
|---|---|
| Create an invoice | `$client->invoices->create([...], idempotencyKey: '...')` |
| List invoices | `$client->invoices->list([...])` |
| Retrieve | `$client->invoices->retrieve($id)` |
| Timeline | `$client->invoices->timeline($id)` |
| Cancel | `$client->invoices->cancel($id)` |
| Test-mode simulate | `$client->invoices->simulate($id, ['event' => 'paid'])` |
| Public snapshot (no auth) | `$client->invoices->publicSnapshot($id)` |
| Webhook endpoints CRUD | `$client->webhooks->{list, create, update, delete, rotate, test}($storeId, ...)` |
| Delivery history | `$client->webhooks->deliveries($storeId)` |
| Verify a webhook | `WebhookSignature::verify($rawBody, $header, $secret)` |

For endpoints we haven't wrapped yet, drop to the raw HTTP:

```php
$client->request('POST', '/v1/some/new/endpoint', ['key' => 'value']);
```

## Going live checklist

1. Store has a mainnet xpub registered.
2. Store's `test_mode` toggle is off.
3. `SATLANE_API_KEY` is the `sl_live_...` variant.
4. Webhook URL is HTTPS and reachable from the public internet.
5. Your handler responds `2xx` quickly (`<10s` timeout), persists the side effect synchronously, and dedupes by `event_id`.
6. You verify the signature on every request.
7. You handle `invoice.payment_reverted` (rare but real — reverse fulfillment on chain reorgs).

## Links

- [Quickstart guide](https://satlane.com/docs/quickstart)
- [API reference](https://satlane.com/docs/api)
- [Webhook events](https://satlane.com/docs/events)
- [Invoice statuses](https://satlane.com/docs/statuses)
- [Errors](https://satlane.com/docs/errors)

## License

MIT.

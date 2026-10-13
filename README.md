# SatLane PHP SDK

Server-side invoice creation and webhook signature verification for SatLane Bitcoin payments. The 0.2 release updates the SDK for the wallet-free Sandbox contract and safer retries.

## Install

PHP 8.1 or newer and Guzzle 7 are required. Once 0.2 is published:

```bash
composer require satlane/satlane-php:^0.2
```

Before publication, use the monorepo package through a Composer path repository. Never place your API key in browser JavaScript.

## Create a payment request

```php
$client = new \Satlane\Client(apiKey: getenv('SATLANE_API_KEY'));
$invoice = $client->invoices->create([
    'amount' => 49.99,
    'currency' => 'USD',
    'order_ref' => 'ORDER-123',
    'buyer_email' => 'buyer@example.com',
], idempotencyKey: 'order-123-checkout-1');
header('Location: ' . $invoice['hosted_checkout_url']);
```

Use a store-scoped Test key (`sl_test_...`) or Live key (`sl_live_...`). An API key is bound to one store and environment. Keep a separate client for each connection. Configure webhook endpoints and signing secrets in the merchant dashboard; `callback_url` does not create a webhook endpoint.

A Test client sends `SatLane-Version: 2026-10-05` automatically. Wallet-free Sandbox must be enabled on the server and does not require a wallet or real Bitcoin. Unsupported/disabled contract errors are surfaced rather than silently falling back. A legacy server can be selected explicitly with `contractVersion: null`; it uses the legacy server's test-wallet rules. Live requires explicit store activation, an active mainnet receiving wallet, account eligibility and synchronized chain services. Choosing the Live view alone does not activate a store.

`Client::fromEnv()` reads `SATLANE_API_KEY` and optional `SATLANE_API_BASE`. For custom configuration, use the constructor:

```php
$client = new \Satlane\Client(
    apiKey: $key,
    baseUrl: 'https://api.satlane.com',
    timeoutSeconds: 30,
    maxRetries: 3,
    contractVersion: '2026-10-05',
);
```

## Invoice methods

- `create($params, $idempotencyKey)` returns the invoice array.
- `list($query)` returns invoices plus pagination metadata; pass the returned cursor for the next page.
- `retrieve($id)` returns the authenticated invoice array.
- `timeline($id)` is a deprecated dashboard-only operation and fails with guidance; use `retrieve` for an API-key snapshot.
- `cancel($id)` follows the API's current cancellation eligibility rules.
- `simulate($id, ['event' => 'paid', 'action_id' => 'scenario-1'])` and `simulatePaid($id, 'scenario-1')` exercise permitted Sandbox transitions. They cannot simulate Live payments. A missing action ID is generated per call; persist an explicit ID when retrying the same action across separate calls.
- `publicSnapshot($id)` reads the public checkout snapshot without sending your API key.

Response arrays retain extra fields, including `payment_phase`, `payment_summary`, nullable Sandbox destinations and optional `checkout_urls`. Use `hosted_checkout_url` for the standard hosted checkout. A browser should not receive the private API key.

## Retry and error handling

The SDK retries safe reads and requests carrying an idempotency key after network errors, 429 or 5xx. It does not retry other mutations automatically. `Retry-After` supports seconds and HTTP dates, with a 60-second maximum delay.

Invoice creation generates one key per method call if omitted, preserving it through internal retries. **Provide and persist your own key for order-level retries across separate calls.** The server's idempotency retention is 24 hours; keep the invoice ID on your order rather than assuming indefinite deduplication.

`ApiException` exposes `status`, `code()`, `requestId()` and `details()`. A network failure has status 0; invalid JSON success responses produce `invalid_response`. `isRetryable()` describes transient error categories, not permission to blindly retry a payment mutation. Billing locks and readiness errors should be shown clearly to the merchant.

## Receive webhooks

Verify the exact raw request bytes before parsing or acting on them:

```php
\Satlane\WebhookSignature::verify(
    file_get_contents('php://input'),
    $_SERVER['HTTP_X_SATLANE_SIGNATURE'] ?? '',
    [$currentSecret, $previousSecret],
);
```

Include a previous secret only while your rotation policy allows it. The default timestamp tolerance is five minutes. Catch `SignatureException` and return 400 for invalid signatures. The wire format remains `t=<timestamp>,v1=<HMAC-SHA256>` over `<timestamp>.<raw_body>`.

Durably save/deduplicate `event_id` and complete required processing before acknowledging with 2xx, or commit a durable job before acknowledgement. Database or processing failures must not be mistaken for completed duplicates. Every business fulfillment action must also be idempotent. Laravel applications should use `satlane/satlane-laravel` for the built-in receiver.

Confirmed `paid` and `late_paid` events are normal fulfillment triggers. Review `overpaid` according to your business policy. Never fulfill from `payment_seen` alone, or ask for another full payment while an already received balance is still confirming. Process `payment_reverted` and review events. Events may be duplicated or delivered out of order; signatures authenticate them but do not establish ordering.

## Webhook management migration

The old `$client->webhooks` management helpers target dashboard session/TOTP routes, not API-key routes. In 0.2 they fail immediately with actionable guidance instead of sending requests that cannot authenticate. Set up and manage endpoints in the SatLane dashboard. Signature verification remains supported.

## Development

```bash
composer install
composer validate --strict
composer test
```

See [AI integration guidance](AI-INTEGRATION.md), [upgrade notes](UPGRADING.md) and https://satlane.com/docs. The monorepo owns the source; dedicated public repositories are release mirrors.

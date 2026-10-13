<?php

declare(strict_types=1);

namespace Satlane\Resources;

use Satlane\Client;

/**
 * Invoice resource : create, list, retrieve, cancel, simulate.
 *
 *   $client->invoices->create([...]);
 *   $client->invoices->retrieve('22872e14-...');
 *   $client->invoices->cancel('22872e14-...');
 */
final class Invoices
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param array<string,mixed> $params
     *   See https://satlane.com/docs/api : `amount` + `currency` OR
     *   `amount_sats`, plus optional `order_ref`, `callback_url`,
     *   `success_url`, `buyer_email`, `expires_in_minutes`, `metadata`.
     * @param string|null $idempotencyKey
     *   Recommended for every create call. We cache the response per
     *   key for 24h so retries return the same invoice instead of
     *   creating duplicates. Pass the same key with the same body to
     *   replay safely; a different body with the same key is an error.
     *
     * @return array<string,mixed> The public invoice payload.
     */
    public function create(array $params, ?string $idempotencyKey = null): array
    {
        $idempotencyKey ??= bin2hex(random_bytes(16));
        $headers = [];
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $res = $this->client->request('POST', '/v1/invoices', $params, $headers);
        return $res['invoice'] ?? $res;
    }

    /**
     * @param array<string,mixed> $query
     *   Filters: `status`, `from`, `to`, `order_ref`, `q`, `buyer_email`,
     *   `amount_min`, `amount_max`, `cursor`, `limit`.
     *
     * @return array<string,mixed>
     */
    public function list(array $query = []): array
    {
        $qs = http_build_query($query);
        $path = '/v1/invoices' . ($qs ? "?$qs" : '');
        return $this->client->request('GET', $path);
    }

    /**
     * @return array<string,mixed> Invoice payload.
     */
    public function retrieve(string $id): array
    {
        $res = $this->client->request('GET', "/v1/invoices/{$id}");
        return $res['invoice'] ?? $res;
    }

    /**
     * @deprecated Dashboard session and TOTP are required; no API-key endpoint.
     * @throws \LogicException Always.
     */
    public function timeline(string $id): array
    {
        throw new \LogicException('Invoice timelines require a merchant dashboard session and TOTP. Use retrieve() for the API-key invoice snapshot or inspect the timeline in the dashboard.');
    }

    /**
     * Cancel an invoice that hasn't been paid yet.
     */
    public function cancel(string $id): array
    {
        $res = $this->client->request('POST', "/v1/invoices/{$id}/cancel");
        return $res['invoice'] ?? $res;
    }

    /**
     * Test-mode only. Run a supported Sandbox simulation action without
     * waiting for chain activity. Reuse action_id for a retried action.
     *
     *   $client->invoices->simulate($id, ['event' => 'paid']);
     *   $client->invoices->simulate($id, ['event' => 'underpaid', 'amount_sats' => '5000']);
     *
     * @param array<string,mixed> $params
     */
    public function simulate(string $id, array $params): array
    {
        $params['action_id'] ??= bin2hex(random_bytes(16));
        return $this->client->request('POST', "/v1/invoices/{$id}/simulate", $params);
    }

    /**
     * Test-mode shorthand for "this invoice was paid".
     */
    public function simulatePaid(string $id, ?string $actionId = null): array
    {
        return $this->client->request('POST', "/v1/invoices/{$id}/simulate_paid", ['action_id' => $actionId ?? bin2hex(random_bytes(16))]);
    }

    /**
     * Live public snapshot. Same endpoint the hosted checkout page
     * polls. Useful if you want to render server-side without keeping
     * an API key in the browser.
     */
    public function publicSnapshot(string $id): array
    {
        // Strip auth for this call : endpoint is intentionally public.
        return $this->client->request('GET', "/pay/invoices/{$id}", authenticated: false);
    }
}

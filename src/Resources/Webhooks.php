<?php

declare(strict_types=1);

namespace Satlane\Resources;

use Satlane\Client;

/**
 * Webhook endpoint management (your store's outbound webhook config).
 *
 * NOTE: this is the management surface — adding / removing / rotating
 * the endpoints SatLane will POST to. For verifying signatures on
 * incoming webhooks see `\Satlane\WebhookSignature`.
 */
final class Webhooks
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function list(string $storeId): array
    {
        return $this->client->request('GET', "/v1/stores/{$storeId}/webhooks");
    }

    /**
     * Returns the endpoint object including the signing secret. The
     * secret is shown ONCE; persist it on creation.
     *
     * @param array<string,mixed> $params  { url, events?: string[], active?: bool }
     */
    public function create(string $storeId, array $params): array
    {
        return $this->client->request('POST', "/v1/stores/{$storeId}/webhooks", $params);
    }

    /**
     * @param array<string,mixed> $params  { url?, events?, active? }
     */
    public function update(string $storeId, string $webhookId, array $params): array
    {
        return $this->client->request(
            'PATCH',
            "/v1/stores/{$storeId}/webhooks/{$webhookId}",
            $params,
        );
    }

    public function delete(string $storeId, string $webhookId): void
    {
        $this->client->request('DELETE', "/v1/stores/{$storeId}/webhooks/{$webhookId}");
    }

    /**
     * Mint a new signing secret. The previous secret stays valid for
     * 24h so you can roll without dropping deliveries.
     *
     * @return array<string,mixed> Endpoint object with the new secret.
     */
    public function rotate(string $storeId, string $webhookId): array
    {
        return $this->client->request(
            'POST',
            "/v1/stores/{$storeId}/webhooks/{$webhookId}/rotate",
        );
    }

    /**
     * Fire a synthetic event to this endpoint right now. Body is a
     * minimal `invoice.test` payload, signature is real.
     */
    public function test(string $storeId, string $webhookId): array
    {
        return $this->client->request(
            'POST',
            "/v1/stores/{$storeId}/webhooks/{$webhookId}/test",
        );
    }

    /**
     * Recent delivery attempts for this store with status, response
     * code, and retry schedule. Use the dashboard for the full UI;
     * this is useful for programmatic monitoring.
     *
     * @param array<string,mixed> $query
     */
    public function deliveries(string $storeId, array $query = []): array
    {
        $qs = http_build_query($query);
        $path = "/v1/stores/{$storeId}/webhook-deliveries" . ($qs ? "?$qs" : '');
        return $this->client->request('GET', $path);
    }
}

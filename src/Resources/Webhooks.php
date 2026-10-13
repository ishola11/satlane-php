<?php

declare(strict_types=1);

namespace Satlane\Resources;

use Satlane\Client;

/** @deprecated Dashboard session/TOTP routes cannot authenticate with an API key.
 * Kept as explicit failures in 0.2 so existing integrations receive clear guidance.
 * Incoming webhook signature verification remains available in WebhookSignature. */
final class Webhooks
{
    public function __construct(private readonly Client $client)
    {
    }
    public function list(string $storeId): array
    {
        return $this->unsupported();
    }
    public function create(string $storeId, array $params): array
    {
        return $this->unsupported();
    }
    public function update(string $storeId, string $webhookId, array $params): array
    {
        return $this->unsupported();
    }
    public function delete(string $storeId, string $webhookId): void
    {
        $this->unsupported();
    }
    public function rotate(string $storeId, string $webhookId): array
    {
        return $this->unsupported();
    }
    public function test(string $storeId, string $webhookId): array
    {
        return $this->unsupported();
    }
    public function deliveries(string $storeId, array $query = []): array
    {
        return $this->unsupported();
    }
    private function unsupported(): never
    {
        throw new \LogicException('Webhook endpoint management requires a merchant dashboard session and TOTP. Configure endpoints in the SatLane dashboard; this API-key SDK verifies incoming webhooks instead.');
    }
}

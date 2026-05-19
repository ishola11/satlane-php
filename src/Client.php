<?php

declare(strict_types=1);

namespace Satlane;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Satlane\Exceptions\ApiException;
use Satlane\Resources\Invoices;
use Satlane\Resources\Webhooks;

/**
 * Main entry point for the SatLane PHP SDK.
 *
 *   $client = new \Satlane\Client(apiKey: getenv('SATLANE_API_KEY'));
 *   $invoice = $client->invoices->create([
 *       'amount'    => 49.99,
 *       'currency'  => 'USD',
 *       'order_ref' => 'ORD-12345',
 *   ]);
 *   header('Location: ' . $invoice['hosted_checkout_url']);
 *
 * Auto-retries (network errors + 5xx + 429) are on by default. 4xx
 * responses fail fast — they're caller bugs and retrying won't help.
 */
final class Client
{
    public readonly Invoices $invoices;
    public readonly Webhooks $webhooks;

    private readonly HttpClient $http;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://api.satlane.com',
        public readonly int $timeoutSeconds = 30,
        public readonly int $maxRetries = 3,
        ?ClientInterface $httpClient = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('SatLane API key cannot be empty.');
        }
        $this->http = $httpClient instanceof HttpClient
            ? $httpClient
            : new HttpClient([
                'base_uri'    => rtrim($this->baseUrl, '/') . '/',
                'timeout'     => $this->timeoutSeconds,
                'http_errors' => false, // we throw our own typed exceptions
            ]);
        $this->invoices = new Invoices($this);
        $this->webhooks = new Webhooks($this);
    }

    /**
     * Build a client from a `SATLANE_API_KEY` env var (and optionally
     * `SATLANE_API_BASE`).
     */
    public static function fromEnv(): self
    {
        $key = getenv('SATLANE_API_KEY') ?: ($_ENV['SATLANE_API_KEY'] ?? null);
        if (!$key) {
            throw new \RuntimeException('SATLANE_API_KEY env var is not set.');
        }
        $base = getenv('SATLANE_API_BASE') ?: ($_ENV['SATLANE_API_BASE'] ?? 'https://api.satlane.com');
        return new self(apiKey: $key, baseUrl: $base);
    }

    /**
     * Low-level HTTP. Resources delegate to this. Public so advanced
     * users can hit endpoints we haven't wrapped yet.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $headers
     *
     * @return array<string,mixed> Parsed JSON response body.
     *
     * @throws ApiException on non-2xx.
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $headers = [],
    ): array {
        $headers['Authorization'] = "Bearer {$this->apiKey}";
        $headers['Accept']        = 'application/json';
        $headers['User-Agent']    = $headers['User-Agent'] ?? 'satlane-php/0.1.0';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $payload = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $response = $this->http->request($method, ltrim($path, '/'), [
                    'headers' => $headers,
                    'body'    => $payload,
                ]);
            } catch (GuzzleException $e) {
                // Network-level failure — retry if we have budget.
                if ($attempt <= $this->maxRetries) {
                    usleep((int) (250_000 * (2 ** ($attempt - 1)))); // 250ms, 500ms, 1s
                    continue;
                }
                throw new ApiException(
                    status: 0,
                    errorCode: 'network_error',
                    requestId: null,
                    details: null,
                    message: 'Could not reach SatLane: ' . $e->getMessage(),
                );
            }

            $status = $response->getStatusCode();
            $bodyText = (string) $response->getBody();
            $bodyJson = $bodyText === '' ? null : json_decode($bodyText, true);

            if ($status >= 200 && $status < 300) {
                return is_array($bodyJson) ? $bodyJson : [];
            }

            // Retryable infra responses.
            if (($status === 429 || $status >= 500) && $attempt <= $this->maxRetries) {
                $sleep = $status === 429
                    ? (int) ($response->getHeaderLine('Retry-After') ?: 1) * 1_000_000
                    : (int) (250_000 * (2 ** ($attempt - 1)));
                usleep($sleep);
                continue;
            }

            $err = is_array($bodyJson) && isset($bodyJson['error']) ? $bodyJson['error'] : [];
            throw new ApiException(
                status: $status,
                errorCode: $err['code'] ?? null,
                requestId: $err['request_id'] ?? null,
                details: $err['details'] ?? null,
                message: $err['message'] ?? "SatLane request failed (HTTP {$status}).",
            );
        }
    }
}

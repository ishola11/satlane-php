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
 * Safe reads and idempotency-protected requests retry transient failures.
 * Other mutations fail without automatic retries.
 */
final class Client
{
    public const VERSION = '0.2.0';
    public readonly Invoices $invoices;
    public readonly Webhooks $webhooks;

    private readonly ClientInterface $http;
    private readonly \Closure $sleep;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = 'https://api.satlane.com',
        public readonly int $timeoutSeconds = 30,
        public readonly int $maxRetries = 3,
        ?ClientInterface $httpClient = null,
        public readonly ?string $contractVersion = '2026-10-05',
        ?callable $sleeper = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('SatLane API key cannot be empty.');
        }
        $this->http = $httpClient ?? new HttpClient([
                'base_uri'    => rtrim($this->baseUrl, '/') . '/',
                'timeout'     => $this->timeoutSeconds,
                'http_errors' => false, // we throw our own typed exceptions
            ]);
        if ($timeoutSeconds <= 0 || $maxRetries < 0 || $maxRetries > 10) {
            throw new \InvalidArgumentException('Timeout must be positive and retries between 0 and 10.');
        }
        $this->sleep = $sleeper ? \Closure::fromCallable($sleeper) : static fn (int $micros) => usleep($micros);
        $this->invoices = new Invoices($this);
        $this->webhooks = new Webhooks($this);
    }

    public function invoices(): Invoices
    {
        return $this->invoices;
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks;
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
        bool $authenticated = true,
    ): array {
        $method = strtoupper($method);
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $path) || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('Request path must be relative to the configured API.');
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        unset($headers['authorization']);
        if ($authenticated) {
            $headers['authorization'] = "Bearer {$this->apiKey}";
        }
        if ($authenticated && str_starts_with($this->apiKey, 'sl_test_') && $this->contractVersion !== null) {
            $headers['satlane-version'] ??= $this->contractVersion;
        }
        $safeRetry = in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) || !empty($headers['idempotency-key']);
        $headers['accept']        = 'application/json';
        $headers['user-agent']    = $headers['user-agent'] ?? 'satlane-php/' . self::VERSION;
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
        }

        $payload = $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                $request = new \GuzzleHttp\Psr7\Request($method, rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), $headers, $payload);
                $response = $this->http->send($request, [
                    'headers' => null, // Do not inherit an injected client's default credentials.
                    'auth' => null,
                    'cookies' => false,
                    'allow_redirects' => false,
                    'timeout' => $this->timeoutSeconds,
                    'http_errors' => false,
                ]);

            } catch (GuzzleException $e) {
                if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->getResponse() !== null) {
                    $response = $e->getResponse();
                } else {
                    if ($safeRetry && $attempt <= $this->maxRetries) {
                        ($this->sleep)((int) (250_000 * (2 ** ($attempt - 1))));
                        continue;
                    }
                    throw new ApiException(0, 'network_error', null, null, 'Could not reach SatLane.');
                }
            }

            $status = $response->getStatusCode();
            $bodyText = (string) $response->getBody();
            $bodyJson = $bodyText === '' ? null : json_decode($bodyText, true);

            if ($status >= 200 && $status < 300) {
                if ($bodyText === '' && $status === 204) {
                    return [];
                }
                if (!is_array($bodyJson) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new ApiException($status, 'invalid_response', null, null, 'SatLane returned an invalid JSON response.');
                }
                return $bodyJson;
            }

            // Retryable infra responses.
            if (($status === 429 || $status >= 500) && $safeRetry && $attempt <= $this->maxRetries) {
                $retryAfter = trim($response->getHeaderLine('Retry-After'));
                $seconds = ctype_digit($retryAfter) ? (float) $retryAfter : (($date = strtotime($retryAfter)) !== false ? max(0, $date - time()) : null);
                $sleep = $seconds !== null ? (int) (min(60, $seconds) * 1_000_000) : (int) (250_000 * (2 ** ($attempt - 1)));
                ($this->sleep)($sleep);
                continue;
            }

            $err = is_array($bodyJson) && is_array($bodyJson['error'] ?? null) ? $bodyJson['error'] : [];
            throw new ApiException(
                status: $status,
                errorCode: is_string($err['code'] ?? null) ? $err['code'] : null,
                requestId: is_string($err['request_id'] ?? null) ? $err['request_id'] : null,
                details: is_array($err['details'] ?? null) ? $err['details'] : null,
                message: is_string($err['message'] ?? null) ? $err['message'] : "SatLane request failed (HTTP {$status}).",
            );
        }
    }
}

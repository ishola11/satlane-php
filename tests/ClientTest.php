<?php

declare(strict_types=1);

namespace Satlane\Tests;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Satlane\Client;
use Satlane\Exceptions\ApiException;

final class ClientTest extends TestCase
{
    private function client(array $responses, array &$calls, array &$delays, string $key = 'sl_test_fixture'): Client
    {
        $http = $this->createMock(ClientInterface::class);
        $http->method('send')->willReturnCallback(function ($request, $options) use (&$responses, &$calls) {
            $options['headers'] = array_change_key_case(array_map(static fn ($values) => implode(', ', $values), $request->getHeaders()), CASE_LOWER);
            $options['body'] = (string) $request->getBody();
            $calls[] = [$request->getMethod(), (string) $request->getUri(), $options];
            $r = array_shift($responses);
            if ($r instanceof \Throwable) {
                throw $r;
            }return $r;
        });
        return new Client($key, httpClient:$http, sleeper:static function ($us) use (&$delays) {
            $delays[] = $us;
        });
    }
    public function testPublicRequestClearsInjectedDefaultCredentials(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new Response(200, [], '{}')]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $http = new \GuzzleHttp\Client(['handler' => $stack,'headers' => ['Authorization' => 'Bearer old-secret'],'auth' => ['name','password']]);
        (new Client('sl_test_fixture', httpClient:$http))->invoices->publicSnapshot('fixture');
        self::assertFalse($history[0]['request']->hasHeader('Authorization'));
    }

    public function testResourceGettersSupportLaravelFacadeCalls(): void
    {
        $calls = $delays = [];
        $client = $this->client([], $calls, $delays);
        self::assertSame($client->invoices, $client->invoices());
        self::assertSame($client->webhooks, $client->webhooks());
    }

    public function testHttpExceptionPreservesItsResponse(): void
    {
        $calls = $delays = [];
        $exception = new \GuzzleHttp\Exception\RequestException('HTTP failed', new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.test'), new Response(401, [], '{"error":{"code":"api_key_invalid"}}'));
        $c = $this->client([$exception], $calls, $delays);
        try {
            $c->request('GET', '/v1/invoices');
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('api_key_invalid', $e->code());
        }
        self::assertCount(1, $calls);
    }
    public function testNetworkReadRetriesButUnsafeWriteDoesNot(): void
    {
        $calls = $delays = [];
        $error = new \GuzzleHttp\Exception\ConnectException('offline', new \GuzzleHttp\Psr7\Request('GET', 'https://api.example.test'));
        $c = $this->client([$error,new Response(200, [], '{}')], $calls, $delays);
        $c->request('GET', '/v1/invoices');
        self::assertCount(2, $calls);
        $calls = $delays = [];
        $c = $this->client([$error], $calls, $delays);
        try {
            $c->request('POST', '/mutation');
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(0, $e->status);
            self::assertTrue($e->isRetryable());
        }
        self::assertCount(1, $calls);
    }
    public function testHttpDateRetryAfterIsAccepted(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 2)]),new Response(200, [], '{}')], $calls, $delays);
        $c->request('GET', '/v1/invoices');
        self::assertGreaterThan(0, $delays[0]);
        self::assertLessThanOrEqual(2000000, $delays[0]);
    }
    public function testCreateRetriesWithStableIdempotencyAndSandboxContract(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(500),new Response(201, [], json_encode(['invoice' => ['id' => 'ok']]))], $calls, $delays);
        self::assertSame('ok', $c->invoices->create(['amount_sats' => '1000'])['id']);
        self::assertCount(2, $calls);
        self::assertNotEmpty($calls[0][2]['headers']['idempotency-key']);
        self::assertSame($calls[0][2]['headers']['idempotency-key'], $calls[1][2]['headers']['idempotency-key']);
        self::assertSame('2026-10-05', $calls[0][2]['headers']['satlane-version']);
        self::assertFalse($calls[0][2]['http_errors']);
    }
    public function testUnsafeMutationWithoutKeyIsNotRetried(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(503)], $calls, $delays);
        try {
            $c->request('POST', '/mutation', []);
            self::fail();
        } catch (ApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertCount(1, $calls);
        self::assertSame([], $delays);
    }
    public function testPublicSnapshotDoesNotTransmitApiKey(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(200, [], '{"invoice":{}}')], $calls, $delays);
        $c->invoices->publicSnapshot('fixture');
        self::assertArrayNotHasKey('authorization', $calls[0][2]['headers']);
    }
    public function testInjectedInterfacePreservesApiErrorsWithoutRetry(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(400, [], '{"error":{"code":"validation","message":"Bad value","request_id":"req_1"}}')], $calls, $delays);
        try {
            $c->request('GET', '/v1/invoices');
            self::fail();
        } catch (ApiException $e) {
            self::assertSame('validation', $e->code());
            self::assertSame('req_1', $e->requestId());
        }
        self::assertCount(1, $calls);
    }
    public function testMalformedSuccessIsAnError(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(200, [], 'invalid')], $calls, $delays);
        $this->expectException(ApiException::class);
        $c->request('GET', '/v1/invoices');
    }
    public function testNoContentSucceeds(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(204)], $calls, $delays);
        self::assertSame([], $c->request('DELETE', '/fixture'));
    }
    public function testRetryAfterIsBounded(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(429, ['Retry-After' => '9999999999']),new Response(200, [], '{}')], $calls, $delays);
        $c->request('GET', '/v1/invoices');
        self::assertSame([60000000], $delays);
    }
    public function testLiveKeyDoesNotRequestSandboxContract(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(200, [], '{}')], $calls, $delays, 'sl_live_fixture');
        $c->request('GET', '/v1/invoices');
        self::assertArrayNotHasKey('satlane-version', $calls[0][2]['headers']);
    }
    public function testForeignUrlIsRejectedBeforeHttp(): void
    {
        $calls = $delays = [];
        $c = $this->client([], $calls, $delays);
        $this->expectException(\InvalidArgumentException::class);
        $c->request('GET', 'https://other.example/');
    }
    public function testSimulationSuppliesActionIdAndAcceptsExplicitRetryKey(): void
    {
        $calls = $delays = [];
        $c = $this->client([new Response(200, [], '{}'),new Response(200, [], '{}')], $calls, $delays);
        $c->invoices->simulate('fixture', ['event' => 'paid']);
        $c->invoices->simulatePaid('fixture', 'stable-action');
        self::assertNotEmpty(json_decode($calls[0][2]['body'], true)['action_id']);
        self::assertSame('stable-action', json_decode($calls[1][2]['body'], true)['action_id']);
    }
    public function testDashboardOnlyTimelineFailsWithoutSendingRequest(): void
    {
        $calls = $delays = [];
        $c = $this->client([], $calls, $delays);
        $this->expectException(\LogicException::class);
        $c->invoices->timeline('fixture');
    }
    public function testLegacyContractCanBeExplicitlySelected(): void
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('send')->willReturnCallback(function ($request, $options) {
            self::assertFalse($request->hasHeader('satlane-version'));
            return new Response(200, [], '{}');
        });
        (new Client('sl_test_fixture', httpClient:$http, contractVersion:null))->request('GET', '/v1/invoices');
    }
    public function testWebhookManagementFailsWithActionableGuidance(): void
    {
        $calls = $delays = [];
        $c = $this->client([], $calls, $delays);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('dashboard');
        $c->webhooks->list('store');
    }
}

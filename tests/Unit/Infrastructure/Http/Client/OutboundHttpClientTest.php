<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http\Client;

use App\Infrastructure\Http\Client\HttpException;
use App\Infrastructure\Http\Client\HttpResponse;
use App\Infrastructure\Http\Client\HttpTransportInterface;
use App\Infrastructure\Http\Client\OutboundHttpClient;
use App\Infrastructure\Logging\CorrelationIdService;
use Psr\Log\NullLogger;
use Tests\Support\UnitTestCase;

final class OutboundHttpClientTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
        CorrelationIdService::set('out-corr-1');
    }

    protected function tearDown(): void
    {
        CorrelationIdService::clear();
        parent::tearDown();
    }

    public function test_get_passes_headers_through_and_returns_response(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(200, '{"ok":true}', ['content-type' => 'application/json']));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $response = $client->get('https://api.test/things', ['X-Custom' => '1']);

        $this->assertSame(200, $response->statusCode);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('1', $transport->lastHeaders['X-Custom']);
    }

    public function test_correlation_id_is_propagated_automatically(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(204, '', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $client->get('https://api.test/probe');

        $this->assertSame('out-corr-1', $transport->lastHeaders['X-Correlation-Id']);
    }

    public function test_mutating_method_gets_idempotency_key_by_default(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(201, '{}', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $client->postJson('https://api.test/orders', ['x' => 1]);

        $this->assertArrayHasKey('Idempotency-Key', $transport->lastHeaders);
        $this->assertNotSame('', $transport->lastHeaders['Idempotency-Key']);
    }

    public function test_caller_supplied_idempotency_key_is_preserved(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(200, '{}', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $client->request('POST', 'https://api.test/x', ['Idempotency-Key' => 'caller-fixed'], '{}');

        $this->assertSame('caller-fixed', $transport->lastHeaders['Idempotency-Key']);
    }

    public function test_get_request_does_not_get_idempotency_key(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(200, '{}', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $client->get('https://api.test/things');

        $this->assertArrayNotHasKey('Idempotency-Key', $transport->lastHeaders);
    }

    public function test_5xx_status_triggers_retry_and_eventually_returns(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(503, '', []));
        $transport->respond(new HttpResponse(503, '', []));
        $transport->respond(new HttpResponse(200, 'ok', []));

        $client = new OutboundHttpClient(
            transport: $transport,
            logger: new NullLogger(),
            maxAttempts: 3,
            backoffSeconds: [0, 0, 0]
        );

        $response = $client->get('https://api.test/sometimes-down');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(3, $transport->calls);
    }

    public function test_exhausted_retries_on_5xx_returns_last_response(): void
    {
        $transport = new RecordingTransport();
        for ($i = 0; $i < 3; $i++) {
            $transport->respond(new HttpResponse(503, 'down', []));
        }

        $client = new OutboundHttpClient(
            transport: $transport,
            logger: new NullLogger(),
            maxAttempts: 3,
            backoffSeconds: [0, 0]
        );

        $response = $client->get('https://api.test/always-down');

        // Exhausted retries return the LAST response so the caller can inspect it.
        $this->assertSame(503, $response->statusCode);
        $this->assertSame(3, $transport->calls);
    }

    public function test_network_exception_is_retried_then_wrapped(): void
    {
        $transport = new RecordingTransport();
        $transport->throw(new \RuntimeException('connection reset'));
        $transport->throw(new \RuntimeException('connection reset'));
        $transport->throw(new \RuntimeException('connection reset'));

        $client = new OutboundHttpClient(
            transport: $transport,
            logger: new NullLogger(),
            maxAttempts: 3,
            backoffSeconds: [0, 0]
        );

        try {
            $client->get('https://unreachable.test/x');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertStringContainsString('failed after 3 attempt', $e->getMessage());
            $this->assertSame(3, $transport->calls);
        }
    }

    public function test_get_json_throws_when_response_is_not_successful(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(404, '{"error":"missing"}', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);

        try {
            $client->getJson('https://api.test/missing');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->lastResponse?->statusCode);
        }
    }

    public function test_get_json_decodes_body_on_success(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(200, '{"items":[1,2,3]}', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 1);
        $payload = $client->getJson('https://api.test/items');

        $this->assertSame([1, 2, 3], $payload['items']);
    }

    public function test_non_retryable_status_returns_immediately(): void
    {
        $transport = new RecordingTransport();
        $transport->respond(new HttpResponse(400, 'bad', []));

        $client = new OutboundHttpClient($transport, new NullLogger(), maxAttempts: 3, backoffSeconds: [0]);
        $response = $client->get('https://api.test/x');

        $this->assertSame(400, $response->statusCode);
        $this->assertSame(1, $transport->calls, '400 is not retryable');
    }
}

/**
 * In-test transport double that records calls and replays a queue of
 * responses (or thrown exceptions).
 */
final class RecordingTransport implements HttpTransportInterface
{
    public int $calls = 0;
    /** @var array<string, string> */
    public array $lastHeaders = [];
    /** @var list<HttpResponse|\Throwable> */
    private array $queue = [];

    public function respond(HttpResponse $response): void
    {
        $this->queue[] = $response;
    }

    public function throw(\Throwable $error): void
    {
        $this->queue[] = $error;
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body, float $timeoutSeconds): HttpResponse
    {
        $this->calls++;
        $this->lastHeaders = $headers;

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('RecordingTransport queue exhausted');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}

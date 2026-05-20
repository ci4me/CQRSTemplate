<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Http\Middleware;

use App\Infrastructure\Http\Middleware\IdempotencyMiddleware;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\App;
use Config\Database;

final class IdempotencyMiddlewareTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    /** @var bool */
    protected $migrate = true;
    /** @var bool */
    protected $refresh = true;
    /** @var string|null */
    protected $namespace = null;

    protected function setUp(): void
    {
        parent::setUp();
        CorrelationIdService::clear();
    }

    public function test_get_requests_pass_through_untouched(): void
    {
        $mw = new IdempotencyMiddleware();
        $request = $this->makeRequest('GET', '/api/v1/users', 'KEY-12345678');

        $result = $mw->before($request);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RequestInterface::class, $result);
    }

    public function test_post_without_key_passes_through(): void
    {
        $mw = new IdempotencyMiddleware();
        $request = $this->makeRequest('POST', '/api/v1/users', '');

        $result = $mw->before($request);

        $this->assertInstanceOf(\CodeIgniter\HTTP\RequestInterface::class, $result);
    }

    public function test_post_with_invalid_key_returns_422(): void
    {
        $mw = new IdempotencyMiddleware();
        $request = $this->makeRequest('POST', '/api/v1/users', 'has spaces');

        $result = $mw->before($request);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->getStatusCode());
    }

    public function test_first_request_passes_through_and_after_caches(): void
    {
        $mw = new IdempotencyMiddleware();
        $request = $this->makeRequest('POST', '/api/v1/users', 'first-time-abc-12');

        // First call: nothing cached yet — should let request through.
        $beforeResult = $mw->before($request);
        $this->assertInstanceOf(\CodeIgniter\HTTP\RequestInterface::class, $beforeResult);

        // After-filter: simulate controller's response.
        $response = (new Response(new App()))
            ->setStatusCode(201)
            ->setJSON(['id' => 7]);
        $mw->after($request, $response);

        // The row must now exist.
        $row = Database::connect()->table('idempotency_keys')
            ->where('id_key', 'first-time-abc-12')
            ->get()
            ->getRowArray();
        $this->assertNotNull($row);
        $this->assertSame(201, (int) $row['status_code']);
    }

    public function test_replay_returns_cached_response_when_same_request(): void
    {
        $mw = new IdempotencyMiddleware();
        $request = $this->makeRequest('POST', '/api/v1/users', 'replay-key-1234');

        // First pass: cache it.
        $mw->before($request);
        $response = (new Response(new App()))
            ->setStatusCode(201)
            ->setJSON(['cached' => 'payload']);
        $mw->after($request, $response);

        // Second hit with the same key + same body should be replayed.
        $request2 = $this->makeRequest('POST', '/api/v1/users', 'replay-key-1234');
        $replay = $mw->before($request2);

        $this->assertInstanceOf(Response::class, $replay);
        $this->assertSame(201, $replay->getStatusCode());
        $this->assertSame('true', $replay->getHeaderLine('Idempotency-Replayed'));
        $this->assertStringContainsString('cached', (string) $replay->getBody());
    }

    public function test_same_key_different_body_returns_422(): void
    {
        $mw = new IdempotencyMiddleware();
        $first = $this->makeRequest('POST', '/api/v1/users', 'conflict-key-xx', ['a' => 1]);

        $mw->before($first);
        $response = (new Response(new App()))->setStatusCode(201)->setJSON(['id' => 1]);
        $mw->after($first, $response);

        // Same key, DIFFERENT body — must 422.
        $second = $this->makeRequest('POST', '/api/v1/users', 'conflict-key-xx', ['a' => 2]);
        $result = $mw->before($second);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString('conflict', strtolower((string) $result->getBody()));
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     */
    private function makeRequest(string $method, string $path, string $idempotencyKey, ?array $jsonBody = null): \CodeIgniter\HTTP\IncomingRequest
    {
        $config = new App();
        $uri = new \CodeIgniter\HTTP\SiteURI($config);
        $uri->setPath($path);

        $request = new \CodeIgniter\HTTP\IncomingRequest(
            $config,
            $uri,
            $jsonBody === null ? '' : json_encode($jsonBody),
            new \CodeIgniter\HTTP\UserAgent()
        );
        $request->setMethod($method);
        if ($idempotencyKey !== '') {
            $request->setHeader(IdempotencyMiddleware::HEADER, $idempotencyKey);
        }
        if ($jsonBody !== null) {
            $request->setHeader('Content-Type', 'application/json');
        }

        // SECURITY: IdempotencyMiddleware now refuses anonymous (actor_id=0)
        // calls because the (id_key, actor_id) tuple would collide across
        // clients. Attach a synthetic authenticated user so existing tests
        // exercise the success / conflict paths.
        $user = new class {
            public function getId(): int
            {
                return 42;
            }
        };
        /** @phpstan-ignore-next-line dynamic property */
        $request->user = $user;

        return $request;
    }
}

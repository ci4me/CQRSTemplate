<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use App\Infrastructure\Auth\Services\ActorResolver;
use App\Infrastructure\Http\ApiResponse;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Config\Services;

/**
 * RFC-aligned Idempotency-Key handling for mutating API endpoints (D9).
 *
 * Flow:
 * 1. Client generates a unique key per logical operation and submits
 *    `Idempotency-Key: <opaque-string>` on POST/PUT/PATCH/DELETE.
 * 2. On first hit, the middleware lets the request through; the `after`
 *    filter records the response under (id_key, actor_id) and replays it
 *    on any retry within the TTL.
 * 3. On a retry with the SAME body, the cached response is replayed
 *    (status + body + minimal headers).
 * 4. On a retry with the SAME key but DIFFERENT body, a 422 is returned
 *    immediately — clients must not reuse a key for a different request.
 *
 * Safe methods (GET/HEAD/OPTIONS) are passed through untouched. Requests
 * without the header are also passed through (idempotency is opt-in).
 *
 * Apply via filter alias `idempotency` to any API route group that mutates.
 */
final class IdempotencyMiddleware implements FilterInterface
{
    public const string HEADER = 'Idempotency-Key';
    public const int DEFAULT_TTL_SECONDS = 86400; // 24h

    private const int KEY_MIN = 8;
    private const int KEY_MAX = 128;

    private const array MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface $request
     * @param mixed            $arguments
     * @return RequestInterface|ResponseInterface
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface
    {
        if (!$this->shouldProcess($request)) {
            return $request;
        }

        $key = trim($request->getHeaderLine(self::HEADER));
        if ($key === '') {
            return $request;
        }

        if (!$this->isValidKey($key)) {
            return ApiResponse::validationFailed(
                ['Idempotency-Key' => 'must be 8-128 chars of [A-Za-z0-9._-]'],
                'Invalid Idempotency-Key header.'
            );
        }

        $actorId = $this->actorId($request);

        // SECURITY: an anonymous actor (system, id=0) is a SHARED bucket
        // for every unauthenticated client. Reusing an Idempotency-Key
        // would replay one client's response to another. The middleware
        // is meant to live on auth-gated routes; refuse to operate when
        // the actor is anonymous to make that contract explicit.
        if ($actorId <= 0) {
            return ApiResponse::problem(
                401,
                'Idempotency requires authentication',
                'The Idempotency-Key header can only be used on authenticated requests.'
            );
        }

        $hash = $this->requestHash($request);

        $existing = $this->lookup($key, $actorId);
        if ($existing === null) {
            return $request;
        }

        // Same key, different request body — RFC says respond with 422.
        if ($existing['request_hash'] !== $hash) {
            return ApiResponse::problem(
                422,
                'Idempotency-Key conflict',
                'A different request was previously submitted with this Idempotency-Key. ' .
                'Use a new key, or replay the original request exactly.'
            );
        }

        return $this->replay($existing);
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed             $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        if (!$this->shouldProcess($request)) {
            return $response;
        }

        $key = trim($request->getHeaderLine(self::HEADER));
        if ($key === '' || !$this->isValidKey($key)) {
            return $response;
        }

        $actorId = $this->actorId($request);

        // Don't cache if the row already exists (replayed). The unique index
        // on (id_key, actor_id) would also stop us, but skipping the write
        // avoids a noisy log line.
        if ($this->lookup($key, $actorId) !== null) {
            return $response;
        }

        try {
            $now = new \DateTimeImmutable();
            $expires = $now->modify('+' . self::DEFAULT_TTL_SECONDS . ' seconds');

            Database::connect()->table('idempotency_keys')->insert([
                'id_key' => $key,
                'actor_id' => $actorId,
                'method' => strtoupper($request->getMethod()),
                'uri' => $request->getUri()->getPath(),
                'request_hash' => $this->requestHash($request),
                'status_code' => $response->getStatusCode(),
                'response_body' => (string) $response->getBody(),
                'response_headers' => json_encode([
                    'Content-Type' => $response->getHeaderLine('Content-Type'),
                ]),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Caching is best-effort; never break the user-facing response.
            log_message('warning', 'IdempotencyMiddleware after-store failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * shouldProcess.
     *
     * @param RequestInterface $request
     * @return bool
     */
    private function shouldProcess(RequestInterface $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::MUTATING_METHODS, true);
    }

    /**
     * isValidKey.
     *
     * @param string $key
     * @return bool
     */
    private function isValidKey(string $key): bool
    {
        $len = strlen($key);
        if ($len < self::KEY_MIN || $len > self::KEY_MAX) {
            return false;
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $key) === 1;
    }

    /**
     * actorId.
     *
     * @param RequestInterface $request
     * @return int
     */
    private function actorId(RequestInterface $request): int
    {
        return (new ActorResolver())->resolve($request)->id;
    }

    /**
     * requestHash.
     *
     * @param RequestInterface $request
     * @return string
     */
    private function requestHash(RequestInterface $request): string
    {
        $parts = [
            strtoupper($request->getMethod()),
            $request->getUri()->getPath(),
            (string) $request->getBody(),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * @param string $key
     * @param int    $actorId
     * @return array{request_hash: string, status_code: int, response_body: string, response_headers: string, expires_at: string}|null
     */
    private function lookup(string $key, int $actorId): ?array
    {
        $now = date('Y-m-d H:i:s');

        try {
            $result = Database::connect()
                ->table('idempotency_keys')
                ->where('id_key', $key)
                ->where('actor_id', $actorId)
                ->where('expires_at >', $now)
                ->get();

            if ($result === false) {
                return null;
            }

            $row = $result->getRowArray();
            if ($row === null) {
                return null;
            }

            return [
                'request_hash' => (string) ($row['request_hash'] ?? ''),
                'status_code' => (int) ($row['status_code'] ?? 0),
                'response_body' => (string) ($row['response_body'] ?? ''),
                'response_headers' => (string) ($row['response_headers'] ?? '[]'),
                'expires_at' => (string) ($row['expires_at'] ?? ''),
            ];
        } catch (\Throwable) {
            // Table might not exist (e.g. tests without the migration).
            return null;
        }
    }

    /**
     * @param array{status_code: int, response_body: string, response_headers: string} $row
     * @return ResponseInterface
     */
    private function replay(array $row): ResponseInterface
    {
        $response = Services::response();
        $response->setStatusCode($row['status_code']);
        $response->setBody($row['response_body']);

        $headers = json_decode($row['response_headers'], true);
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (!is_string($name) || !is_string($value) || $value === '') {
                    continue;
                }
                $response->setHeader($name, $value);
            }
        }

        $response->setHeader('Idempotency-Replayed', 'true');
        $response->setHeader('X-Correlation-Id', CorrelationIdService::get());

        return $response;
    }
}

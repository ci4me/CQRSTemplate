<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Client;

use App\Infrastructure\Logging\CorrelationIdService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * High-level outbound HTTP client (D18).
 *
 * What this layer adds on top of the raw {@see HttpTransportInterface}:
 *  - retry with exponential backoff on transient failures (network errors
 *    and 5xx / 429 responses)
 *  - automatic Idempotency-Key generation for mutating requests so the
 *    remote service can dedupe our retries
 *  - automatic correlation ID propagation (X-Correlation-Id) so the remote
 *    service can stitch traces back to this request
 *  - JSON convenience: postJson / getJson encode and decode for you
 *  - shape-preserving logging — full URL + status + duration + correlation
 *    id, no payload (could be sensitive)
 *
 * Construction is verbose so the client stays testable without globals.
 * Production users typically rely on Services::outboundHttpClient().
 */
final readonly class OutboundHttpClient
{
    public function __construct(
        private HttpTransportInterface $transport,
        private LoggerInterface $logger = new NullLogger(),
        private int $maxAttempts = 3,
        private float $timeoutSeconds = 10.0,
        /** @var list<int> Status codes that trigger a retry. */
        private array $retryStatuses = [408, 425, 429, 500, 502, 503, 504],
        /** @var list<int> Backoff schedule between attempts in seconds. */
        private array $backoffSeconds = [1, 3, 10]
    ) {
    }

    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers, '');
    }

    /**
     * @param array<string, string> $headers
     */
    public function getJson(string $url, array $headers = []): mixed
    {
        $headers['Accept'] = 'application/json';
        $response = $this->get($url, $headers);
        $this->ensureSuccessful($response, 'GET', $url);
        return $response->json();
    }

    /**
     * @param array<string, mixed>   $payload
     * @param array<string, string>  $headers
     */
    public function postJson(string $url, array $payload, array $headers = []): HttpResponse
    {
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';
        return $this->request('POST', $url, $headers, $this->encode($payload));
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $upper = strtoupper($method);
        $headers = $this->withDefaultHeaders($upper, $headers);

        $attempts = 0;
        $lastResponse = null;
        $lastError = null;

        while ($attempts < $this->maxAttempts) {
            $attempts++;
            $start = microtime(true);

            try {
                $response = $this->transport->send($upper, $url, $headers, $body, $this->timeoutSeconds);
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logger->warning('Outbound HTTP transport failure', [
                    'component' => 'OutboundHttpClient',
                    'method' => $upper,
                    'url' => $url,
                    'attempt' => $attempts,
                    'exception' => $e->getMessage(),
                    'correlation_id' => CorrelationIdService::get(),
                ]);
                if ($this->shouldRetry($attempts)) {
                    $this->sleepBackoff($attempts);
                    continue;
                }
                throw new HttpException(
                    sprintf('Outbound HTTP %s %s failed after %d attempt(s)', $upper, $url, $attempts),
                    null,
                    $attempts,
                    $e
                );
            }

            $durationMs = round((microtime(true) - $start) * 1000, 2);
            $lastResponse = $response;

            $this->logger->info('Outbound HTTP response', [
                'component' => 'OutboundHttpClient',
                'method' => $upper,
                'url' => $url,
                'status' => $response->statusCode,
                'attempt' => $attempts,
                'duration_ms' => $durationMs,
                'correlation_id' => CorrelationIdService::get(),
            ]);

            if (in_array($response->statusCode, $this->retryStatuses, true) && $this->shouldRetry($attempts)) {
                $this->sleepBackoff($attempts);
                continue;
            }

            return $response;
        }

        throw new HttpException(
            sprintf('Outbound HTTP %s %s exhausted %d retries', $upper, $url, $this->maxAttempts),
            $lastResponse,
            $attempts,
            $lastError
        );
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function withDefaultHeaders(string $method, array $headers): array
    {
        if (!$this->hasHeader($headers, 'X-Correlation-Id')) {
            $headers['X-Correlation-Id'] = CorrelationIdService::get();
        }

        // RFC-style Idempotency-Key for mutating methods. If the caller
        // already supplied one (e.g. for a retry of an external workflow)
        // we leave it alone.
        if ($this->isMutating($method) && !$this->hasHeader($headers, 'Idempotency-Key')) {
            $headers['Idempotency-Key'] = bin2hex(random_bytes(16));
        }

        return $headers;
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        $needle = strtolower($name);
        foreach (array_keys($headers) as $key) {
            if (strtolower($key) === $needle) {
                return true;
            }
        }
        return false;
    }

    private function isMutating(string $method): bool
    {
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function shouldRetry(int $attempts): bool
    {
        return $attempts < $this->maxAttempts;
    }

    private function sleepBackoff(int $attempts): void
    {
        $idx = min($attempts - 1, count($this->backoffSeconds) - 1);
        $seconds = $this->backoffSeconds[$idx];
        if ($seconds <= 0) {
            return;
        }
        // Tests use a transport that never sleeps; production sleeps the
        // configured seconds. Stay small to keep request paths responsive.
        usleep($seconds * 1_000_000);
    }

    private function ensureSuccessful(HttpResponse $response, string $method, string $url): void
    {
        if ($response->isSuccessful()) {
            return;
        }
        throw new HttpException(
            sprintf('Outbound HTTP %s %s returned %d', $method, $url, $response->statusCode),
            $response
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

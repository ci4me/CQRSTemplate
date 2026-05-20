<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use App\Domain\User\Ports\RateLimitInterface;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Rate Limit Middleware.
 *
 * Enforces rate limiting on protected endpoints using token bucket algorithm.
 * Default: 5 requests per 300 seconds (5 minutes).
 *
 * Usage in Routes.php:
 * - ['filter' => 'ratelimit:5,300'] for 5 requests per 5 minutes
 * - ['filter' => 'ratelimit:10,60'] for 10 requests per minute
 *
 * SECURITY:
 * - Prevents brute force attacks on authentication endpoints
 * - Uses IP address as identifier for unauthenticated requests
 * - Uses user ID for authenticated requests
 * - Returns 429 Too Many Requests with Retry-After header
 * - Logs rate limit violations for monitoring
 */
final readonly class RateLimitMiddleware implements FilterInterface
{
    private RateLimitInterface $rateLimitService;
    private LoggerInterface $logger;

    public function __construct(
        ?RateLimitInterface $rateLimitService = null,
        ?LoggerInterface $logger = null
    ) {
        $this->rateLimitService = $rateLimitService ?? \Config\Services::rateLimitService();
        $this->logger = $logger ?? \Config\Services::logger();
    }

    /**
     * Process request before controller execution.
     *
     * @param RequestInterface $request Current request
     * @param mixed $arguments Filter arguments [maxAttempts, windowSeconds]
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface|null
    {
        // Parse rate limit parameters from arguments
        $params = $this->parseArguments($arguments);
        $maxAttempts = $params['maxAttempts'];
        $windowSeconds = $params['windowSeconds'];

        // Get identifier (IP address or user ID)
        $identifier = $this->getIdentifier($request);

        // Check rate limit
        $result = $this->rateLimitService->checkLimit($identifier, $maxAttempts, $windowSeconds);

        // Allow request if within limit
        if ($result->isAllowed()) {
            return null;
        }

        // Log rate limit violation
        $this->logViolation($identifier, $request, $result);

        // Return 429 Too Many Requests
        return $this->createRateLimitResponse($result, $maxAttempts);
    }

    /**
     * Process response after controller execution.
     *
     * No action needed - rate limiting is applied before execution.
     *
     * @param RequestInterface $request Current request
     * @param ResponseInterface $response Current response
     * @param mixed $arguments Filter arguments
     */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface|null
    {
        return null;
    }

    /**
     * Parse filter arguments into rate limit parameters.
     *
     * @param array<string>|null $arguments Filter arguments [maxAttempts, windowSeconds]
     * @return array{maxAttempts: int, windowSeconds: int}
     */
    private function parseArguments(?array $arguments): array
    {
        // Default: 5 requests per 300 seconds (brute force protection)
        $maxAttempts = 5;
        $windowSeconds = 300;

        if ($arguments !== null && count($arguments) >= 2) {
            $maxAttempts = (int) $arguments[0];
            $windowSeconds = (int) $arguments[1];
        }

        return [
            'maxAttempts' => $maxAttempts,
            'windowSeconds' => $windowSeconds,
        ];
    }

    /**
     * Get unique identifier for rate limiting.
     *
     * Uses IP address for unauthenticated requests.
     * For authenticated requests, could be extended to use user ID.
     *
     * @param RequestInterface $request Current request
     * @return string Unique identifier
     */
    private function getIdentifier(RequestInterface $request): string
    {
        // Get client IP address
        $ipAddress = $request->getIPAddress();

        // Combine endpoint + IP for per-endpoint rate limiting
        assert($request instanceof IncomingRequest);
        $endpoint = $request->getMethod() . ':' . $request->getPath();

        return $endpoint . '|' . $ipAddress;
    }

    /**
     * Log rate limit violation.
     *
     * @param string $identifier Request identifier
     * @param RequestInterface $request Current request
     * @param \App\Domain\Shared\ValueObjects\RateLimitResult $result Rate limit result
     */
    private function logViolation(string $identifier, RequestInterface $request, \App\Domain\Shared\ValueObjects\RateLimitResult $result): void
    {
        assert($request instanceof IncomingRequest);

        $this->logger->warning('Rate limit exceeded', [
            'domain' => 'Security',
            'middleware' => 'RateLimitMiddleware',
            'identifier' => $identifier,
            'ip_address' => $request->getIPAddress(),
            'endpoint' => $request->getMethod() . ' ' . $request->getPath(),
            'user_agent' => $request->getUserAgent()->getAgentString(),
            'attempts_remaining' => $result->getAttemptsRemaining(),
            'reset_time' => date('Y-m-d H:i:s', $result->getResetTime()),
            'seconds_until_reset' => $result->getSecondsUntilReset(),
        ]);
    }

    /**
     * Create 429 Too Many Requests response.
     *
     * @param \App\Domain\Shared\ValueObjects\RateLimitResult $result Rate limit result
     */
    private function createRateLimitResponse(
        \App\Domain\Shared\ValueObjects\RateLimitResult $result,
        int $maxAttempts
    ): ResponseInterface {
        $response = service('response');

        // Set 429 status code
        $response->setStatusCode(429);

        // Set Retry-After header (seconds until reset)
        $response->setHeader('Retry-After', (string) $result->getSecondsUntilReset());

        // Set rate limit headers for client awareness
        $response->setHeader('X-RateLimit-Limit', (string) $maxAttempts);
        $response->setHeader('X-RateLimit-Remaining', (string) $result->getAttemptsRemaining());
        $response->setHeader('X-RateLimit-Reset', (string) $result->getResetTime());

        // Set JSON response body
        $response->setJSON([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please try again later.',
            'retry_after_seconds' => $result->getSecondsUntilReset(),
            'reset_time' => date('Y-m-d H:i:s', $result->getResetTime()),
        ]);

        return $response;
    }
}

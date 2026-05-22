<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Middleware;

use App\Domain\Shared\ValueObjects\RateLimitResult;
use App\Domain\User\Ports\RateLimitInterface;
use App\Infrastructure\Auth\Middleware\RateLimitMiddleware;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Psr\Log\NullLogger;

/**
 * Drives the rate-limit filter's branches: allow, deny+headers, custom
 * argument parsing, and default fallback.
 */
final class RateLimitMiddlewareTest extends CIUnitTestCase
{
    public function test_allows_request_when_rate_limit_service_returns_allowed(): void
    {
        $rl = $this->makeFakeService(new RateLimitResult(true, 4, time() + 60));
        $mw = new RateLimitMiddleware($rl, new NullLogger());

        $result = $mw->before($this->makeRequest(), ['5', '300']);

        $this->assertNull($result);
        $this->assertSame(1, $rl->callCount);
    }

    public function test_returns_429_with_rate_limit_headers_when_denied(): void
    {
        $reset = time() + 120;
        $rl = $this->makeFakeService(new RateLimitResult(false, 0, $reset));
        $mw = new RateLimitMiddleware($rl, new NullLogger());

        $result = $mw->before($this->makeRequest(), ['5', '300']);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(429, $result->getStatusCode());
        $this->assertSame('5', $result->getHeaderLine('X-RateLimit-Limit'));
        $this->assertSame('0', $result->getHeaderLine('X-RateLimit-Remaining'));
        $this->assertSame((string) $reset, $result->getHeaderLine('X-RateLimit-Reset'));
        $this->assertNotEmpty($result->getHeaderLine('Retry-After'));
    }

    public function test_falls_back_to_default_5_per_300_when_no_arguments(): void
    {
        $rl = $this->makeFakeService(new RateLimitResult(true, 5, time() + 300));
        $mw = new RateLimitMiddleware($rl, new NullLogger());

        $mw->before($this->makeRequest(), null);

        $this->assertSame(5, $rl->lastMax);
        $this->assertSame(300, $rl->lastWindow);
    }

    public function test_parses_custom_arguments_and_passes_them_to_service(): void
    {
        $rl = $this->makeFakeService(new RateLimitResult(true, 9, time() + 60));
        $mw = new RateLimitMiddleware($rl, new NullLogger());

        $mw->before($this->makeRequest(), ['10', '60']);

        $this->assertSame(10, $rl->lastMax);
        $this->assertSame(60, $rl->lastWindow);
    }

    public function test_after_filter_returns_null(): void
    {
        $rl = $this->makeFakeService(new RateLimitResult(true, 5, time() + 60));
        $mw = new RateLimitMiddleware($rl, new NullLogger());

        $this->assertNull($mw->after($this->makeRequest(), new Response(new App())));
    }

    private function makeRequest(): IncomingRequest
    {
        $config = new App();
        $uri = new SiteURI($config);
        $uri->setPath('/api/v1/auth/login');
        return new IncomingRequest($config, $uri, '', new UserAgent());
    }

    private function makeFakeService(RateLimitResult $resultToReturn): RateLimitInterface
    {
        return new class ($resultToReturn) implements RateLimitInterface {
            public int $callCount = 0;
            public int $lastMax = 0;
            public int $lastWindow = 0;

            public function __construct(private readonly RateLimitResult $result)
            {
            }

            public function checkLimit(string $identifier, int $maxAttempts, int $windowSeconds): RateLimitResult
            {
                $this->callCount++;
                $this->lastMax = $maxAttempts;
                $this->lastWindow = $windowSeconds;
                return $this->result;
            }

            public function reset(string $identifier): void
            {
            }
        };
    }
}

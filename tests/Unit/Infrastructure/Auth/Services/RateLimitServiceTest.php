<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Services;

use App\Infrastructure\Auth\Services\RateLimitService;
use CodeIgniter\Cache\CacheInterface;
use Tests\Support\UnitTestCase;

/**
 * Drives the token-bucket algorithm with an in-memory cache.
 *
 * Uses a hand-rolled in-memory CacheInterface fake so the test stays in the
 * unit tier and is not coupled to CI4's file/redis backends.
 */
final class RateLimitServiceTest extends UnitTestCase
{
    public function test_first_request_is_allowed_and_consumes_one_token(): void
    {
        $cache = $this->makeCache();
        $svc = new RateLimitService($cache);

        $result = $svc->checkLimit('ip-1', 5, 60);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(4, $result->getAttemptsRemaining());
    }

    public function test_burst_of_max_requests_then_one_more_is_denied(): void
    {
        $cache = $this->makeCache();
        $svc = new RateLimitService($cache);

        for ($i = 0; $i < 5; $i++) {
            $svc->checkLimit('ip-2', 5, 60);
        }

        $denied = $svc->checkLimit('ip-2', 5, 60);

        $this->assertFalse($denied->isAllowed());
        $this->assertSame(0, $denied->getAttemptsRemaining());
        $this->assertGreaterThan(time(), $denied->getResetTime());
    }

    public function test_reset_clears_bucket_state(): void
    {
        $cache = $this->makeCache();
        $svc = new RateLimitService($cache);

        for ($i = 0; $i < 5; $i++) {
            $svc->checkLimit('ip-3', 5, 60);
        }

        $svc->reset('ip-3');

        $afterReset = $svc->checkLimit('ip-3', 5, 60);
        $this->assertTrue($afterReset->isAllowed());
        $this->assertSame(4, $afterReset->getAttemptsRemaining());
    }

    public function test_invalid_max_attempts_throws(): void
    {
        $svc = new RateLimitService($this->makeCache());

        $this->expectException(\InvalidArgumentException::class);
        $svc->checkLimit('ip', 0, 60);
    }

    public function test_invalid_window_seconds_throws(): void
    {
        $svc = new RateLimitService($this->makeCache());

        $this->expectException(\InvalidArgumentException::class);
        $svc->checkLimit('ip', 5, 0);
    }

    public function test_different_identifiers_have_independent_buckets(): void
    {
        $cache = $this->makeCache();
        $svc = new RateLimitService($cache);

        // Drain bucket A.
        for ($i = 0; $i < 5; $i++) {
            $svc->checkLimit('ip-A', 5, 60);
        }

        // Bucket B is still fresh.
        $b = $svc->checkLimit('ip-B', 5, 60);
        $this->assertTrue($b->isAllowed());
        $this->assertSame(4, $b->getAttemptsRemaining());
    }

    private function makeCache(): CacheInterface
    {
        return new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $store = [];

            public function initialize(): void
            {
            }

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function remember(string $key, int $ttl, \Closure $callback): mixed
            {
                if (!array_key_exists($key, $this->store)) {
                    $this->store[$key] = $callback();
                }
                return $this->store[$key];
            }

            public function save(string $key, $value, int $ttl = 60): bool
            {
                $this->store[$key] = $value;
                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);
                return true;
            }

            public function deleteMatching(string $pattern): int
            {
                return 0;
            }

            public function increment(string $key, int $offset = 1): bool|int
            {
                if (!is_int($this->store[$key] ?? null)) {
                    return false;
                }
                $this->store[$key] += $offset;
                /** @var int $value */
                $value = $this->store[$key];
                return $value;
            }

            public function decrement(string $key, int $offset = 1): bool|int
            {
                if (!is_int($this->store[$key] ?? null)) {
                    return false;
                }
                $this->store[$key] -= $offset;
                /** @var int $value */
                $value = $this->store[$key];
                return $value;
            }

            public function clean(): bool
            {
                $this->store = [];
                return true;
            }

            public function getCacheInfo(): ?array
            {
                return null;
            }

            public function getMetaData(string $key): ?array
            {
                return null;
            }

            public function isSupported(): bool
            {
                return true;
            }
        };
    }
}

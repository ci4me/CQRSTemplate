<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Infrastructure\Jobs\JobHandlerInterface;

/**
 * Fixture job handler used by JobQueueTest. State is process-local — each
 * test resets it explicitly.
 */
final class TestJobHandler implements JobHandlerInterface
{
    /** @var list<array<string, mixed>> */
    public static array $invocations = [];
    public static bool $throwOnce = false;
    public static bool $alwaysThrow = false;
    public static int $callCount = 0;

    public static function reset(): void
    {
        self::$invocations = [];
        self::$throwOnce = false;
        self::$alwaysThrow = false;
        self::$callCount = 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload): void
    {
        self::$callCount++;

        if (self::$alwaysThrow) {
            throw new \RuntimeException('handler always fails');
        }

        if (self::$throwOnce) {
            self::$throwOnce = false;
            throw new \RuntimeException('first attempt fails');
        }

        self::$invocations[] = $payload;
    }
}

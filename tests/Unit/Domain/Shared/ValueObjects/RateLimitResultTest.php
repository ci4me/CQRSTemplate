<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\RateLimitResult;
use InvalidArgumentException;
use Tests\Support\UnitTestCase;

final class RateLimitResultTest extends UnitTestCase
{
    public function test_allowed_result_exposes_accessors(): void
    {
        $reset = time() + 60;
        $result = new RateLimitResult(true, 5, $reset);

        $this->assertTrue($result->isAllowed());
        $this->assertSame(5, $result->getAttemptsRemaining());
        $this->assertSame($reset, $result->getResetTime());
    }

    public function test_blocked_result_reports_not_allowed(): void
    {
        $result = new RateLimitResult(false, 0, time() + 30);

        $this->assertFalse($result->isAllowed());
        $this->assertSame(0, $result->getAttemptsRemaining());
    }

    public function test_negative_attempts_remaining_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts remaining cannot be negative');

        new RateLimitResult(true, -1, time() + 10);
    }

    public function test_negative_reset_time_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reset time cannot be negative');

        new RateLimitResult(true, 1, -10);
    }

    public function test_seconds_until_reset_is_never_negative(): void
    {
        $past = new RateLimitResult(true, 5, 1);
        $future = new RateLimitResult(true, 5, time() + 90);

        $this->assertSame(0, $past->getSecondsUntilReset());
        $this->assertGreaterThan(0, $future->getSecondsUntilReset());
        $this->assertLessThanOrEqual(90, $future->getSecondsUntilReset());
    }

    public function test_to_array_serialises_full_state(): void
    {
        $reset = time() + 120;
        $result = new RateLimitResult(true, 3, $reset);

        $payload = $result->toArray();

        $this->assertSame(
            ['allowed', 'attemptsRemaining', 'resetTime', 'secondsUntilReset'],
            array_keys($payload),
        );
        $this->assertTrue($payload['allowed']);
        $this->assertSame(3, $payload['attemptsRemaining']);
        $this->assertSame($reset, $payload['resetTime']);
    }
}

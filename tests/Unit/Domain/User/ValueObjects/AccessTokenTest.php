<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\ValueObjects\AccessToken;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for the AccessToken value object.
 */
final class AccessTokenTest extends UnitTestCase
{
    public function test_creates_access_token_from_string(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');
        $token = AccessToken::fromString('abc.def.ghi', $expires);

        $this->assertSame('abc.def.ghi', $token->getValue());
        $this->assertSame($expires, $token->getExpiresAt());
    }

    public function test_blank_token_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        AccessToken::fromString('   ', new \DateTimeImmutable('+1 hour'));
    }

    public function test_is_expired_returns_true_for_past_dates(): void
    {
        $expired = AccessToken::fromString(
            'expired-token',
            new \DateTimeImmutable('-1 hour')
        );

        $this->assertTrue($expired->isExpired());
    }

    public function test_is_expired_returns_false_for_future_dates(): void
    {
        $fresh = AccessToken::fromString(
            'fresh-token',
            new \DateTimeImmutable('+1 hour')
        );

        $this->assertFalse($fresh->isExpired());
    }

    public function test_equals_returns_true_for_identical_tokens(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');
        $a = AccessToken::fromString('same', $expires);
        $b = AccessToken::fromString('same', $expires);

        $this->assertTrue($a->equals($b));
    }

    public function test_equals_returns_false_for_different_token_values(): void
    {
        $expires = new \DateTimeImmutable('+1 hour');
        $a = AccessToken::fromString('first', $expires);
        $b = AccessToken::fromString('second', $expires);

        $this->assertFalse($a->equals($b));
    }

    public function test_to_string_returns_token_value(): void
    {
        $token = AccessToken::fromString('tok123', new \DateTimeImmutable('+1 hour'));

        $this->assertSame('tok123', (string) $token);
    }
}

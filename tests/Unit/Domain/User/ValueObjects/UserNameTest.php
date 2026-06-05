<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\User\ErrorCodes;
use App\Domain\User\ValueObjects\UserName;
use Tests\Support\UnitTestCase;

final class UserNameTest extends UnitTestCase
{
    public function test_creates_valid_user_name(): void
    {
        $name = UserName::fromString('John Doe');

        $this->assertSame('John Doe', $name->getValue());
        $this->assertSame('John Doe', (string) $name);
    }

    public function test_trims_whitespace(): void
    {
        $name = UserName::fromString('  John Doe  ');

        $this->assertSame('John Doe', $name->getValue());
    }

    public function test_rejects_empty_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User name is required');
        // Round-4 R3: the domain code travels via getErrorCode() (ValidationException),
        // not the PHP \Exception::$code slot.

        UserName::fromString('');
    }

    public function test_rejects_whitespace_only_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User name is required');

        UserName::fromString('   ');
    }

    public function test_rejects_name_too_short(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User name must be at least 2 characters');
        // Round-4 R3: the domain code travels via getErrorCode() (ValidationException),
        // not the PHP \Exception::$code slot.

        UserName::fromString('A');
    }

    public function test_rejects_name_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User name must not exceed 100 characters');
        // Round-4 R3: the domain code travels via getErrorCode() (ValidationException),
        // not the PHP \Exception::$code slot.

        UserName::fromString(str_repeat('A', 101));
    }

    public function test_validation_failures_carry_the_domain_error_code(): void
    {
        // Round-4 R3 contract: UserName throws ValidationException whose
        // getErrorCode() carries USER_VALIDATION_NAME — previously the code
        // was stuffed into \InvalidArgumentException's $code slot, which no
        // error-mapping path reads.
        try {
            UserName::fromString('');
            $this->fail('Expected ValidationException');
        } catch (\App\Domain\Shared\Exceptions\ValidationException $e) {
            $this->assertSame(ErrorCodes::USER_VALIDATION_NAME, $e->getErrorCode());
        }
    }

    public function test_accepts_minimum_length_name(): void
    {
        $name = UserName::fromString('AB');

        $this->assertSame('AB', $name->getValue());
    }

    public function test_accepts_maximum_length_name(): void
    {
        $longName = str_repeat('A', 100);
        $name = UserName::fromString($longName);

        $this->assertSame($longName, $name->getValue());
    }

    public function test_accepts_unicode_characters(): void
    {
        $name = UserName::fromString('José María García');

        $this->assertSame('José María García', $name->getValue());
    }
}

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
        $this->expectExceptionCode(ErrorCodes::USER_VALIDATION_NAME);

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
        $this->expectExceptionCode(ErrorCodes::USER_VALIDATION_NAME);

        UserName::fromString('A');
    }

    public function test_rejects_name_too_long(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User name must not exceed 100 characters');
        $this->expectExceptionCode(ErrorCodes::USER_VALIDATION_NAME);

        UserName::fromString(str_repeat('A', 101));
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\ValueObjects\PasswordComplexity;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for the PasswordComplexity value object.
 *
 * Covers every complexity rule (length, case, digit, special char) plus
 * the boolean predicates used by callers.
 */
final class PasswordComplexityTest extends UnitTestCase
{
    private const string STRONG = 'StrongP@ssw0rd!';

    public function test_accepts_password_meeting_all_requirements(): void
    {
        $complexity = PasswordComplexity::fromPlaintext(self::STRONG);

        $this->assertSame(self::STRONG, $complexity->getValue());
        $this->assertSame(strlen(self::STRONG), $complexity->getLength());
        $this->assertTrue($complexity->meetsMinimumLength());
        $this->assertTrue($complexity->hasUppercase());
        $this->assertTrue($complexity->hasLowercase());
        $this->assertTrue($complexity->hasDigit());
        $this->assertTrue($complexity->hasSpecialCharacter());
    }

    public function test_blank_password_throws_required_exception(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('   ');
    }

    public function test_password_below_minimum_length_throws(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('Aa1!a');
    }

    public function test_password_above_maximum_length_throws(): void
    {
        $this->expectException(ValidationException::class);
        // 130 chars, still has all complexity classes
        $long = str_repeat('Aa1!', 33);
        PasswordComplexity::fromPlaintext($long);
    }

    public function test_password_missing_uppercase_throws(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('lowercase1@only');
    }

    public function test_password_missing_lowercase_throws(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('UPPERCASE1@ONLY');
    }

    public function test_password_missing_digit_throws(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('NoDigitsHere@!');
    }

    public function test_password_missing_special_character_throws(): void
    {
        $this->expectException(ValidationException::class);
        PasswordComplexity::fromPlaintext('NoSpecial1234A');
    }
}

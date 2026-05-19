<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Shared\Exceptions\ValidationException;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for CookieName Value Object.
 *
 * Tests validation rules, normalization, and value object behavior.
 *
 * @package Tests\Unit\Domain\Cookie\ValueObjects
 */
final class CookieNameTest extends UnitTestCase
{
    // ==================== SUCCESSFUL CREATION TESTS ====================

    public function test_can_create_with_valid_name(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString('Chocolate Chip');

        // Assert
        $this->assertInstanceOf(CookieName::class, $cookieName);
        $this->assertEquals('Chocolate Chip', $cookieName->getValue());
    }

    public function test_trims_whitespace_from_name(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString('  Oatmeal Raisin  ');

        // Assert
        $this->assertEquals('Oatmeal Raisin', $cookieName->getValue());
    }

    public function test_accepts_minimum_length_name(): void
    {
        // Arrange & Act - Exactly 3 characters
        $cookieName = CookieName::fromString('ABC');

        // Assert
        $this->assertEquals('ABC', $cookieName->getValue());
        $this->assertEquals(3, $cookieName->getLength());
    }

    public function test_accepts_maximum_length_name(): void
    {
        // Arrange
        $longName = str_repeat('A', 100);  // Exactly 100 characters

        // Act
        $cookieName = CookieName::fromString($longName);

        // Assert
        $this->assertEquals($longName, $cookieName->getValue());
        $this->assertEquals(100, $cookieName->getLength());
    }

    public function test_accepts_name_with_special_characters(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString("Mom's Special Cookie!");

        // Assert
        $this->assertEquals("Mom's Special Cookie!", $cookieName->getValue());
    }

    public function test_accepts_name_with_numbers(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString('Cookie #42');

        // Assert
        $this->assertEquals('Cookie #42', $cookieName->getValue());
    }

    public function test_accepts_unicode_characters(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString('Chocolate 🍪 Cookie');

        // Assert
        $this->assertEquals('Chocolate 🍪 Cookie', $cookieName->getValue());
    }

    // ==================== VALIDATION ERROR TESTS ====================

    public function test_throws_exception_for_empty_string(): void
    {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('required');

        // Act
        CookieName::fromString('');
    }

    public function test_throws_exception_for_whitespace_only(): void
    {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('required');

        // Act
        CookieName::fromString('   ');
    }

    public function test_throws_exception_when_name_too_short(): void
    {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least 3 characters');

        // Act - Only 2 characters
        CookieName::fromString('AB');
    }

    public function test_throws_exception_when_name_too_long(): void
    {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must not exceed 100 characters');

        // Act - 101 characters
        $tooLongName = str_repeat('A', 101);
        CookieName::fromString($tooLongName);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNameProvider')]
    public function test_throws_exception_for_various_invalid_names(
        string $invalidName,
        string $expectedErrorSubstring
    ): void {
        // Arrange
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($expectedErrorSubstring);

        // Act
        CookieName::fromString($invalidName);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'only newline' => ["\n", 'required'],
            'only tab' => ["\t", 'required'],
            'one char' => ['A', 'at least 3 characters'],
            'two chars' => ['AB', 'at least 3 characters'],
            'exactly 101 chars' => [str_repeat('A', 101), 'must not exceed 100 characters'],
            'way too long' => [str_repeat('Cookie', 50), 'must not exceed 100 characters'],
        ];
    }

    // ==================== METHOD TESTS ====================

    public function test_get_length_returns_correct_length(): void
    {
        // Arrange
        $cookieName = CookieName::fromString('Chocolate Chip');

        // Act
        $length = $cookieName->getLength();

        // Assert
        $this->assertEquals(14, $length);
    }

    public function test_get_length_counts_multibyte_characters_correctly(): void
    {
        // Arrange
        $cookieName = CookieName::fromString('Café Cookie');  // é is multibyte

        // Act
        $length = $cookieName->getLength();

        // Assert
        $this->assertEquals(11, $length);  // Not 12 (byte length)
    }

    public function test_equals_returns_true_for_same_value(): void
    {
        // Arrange
        $name1 = CookieName::fromString('Chocolate Chip');
        $name2 = CookieName::fromString('Chocolate Chip');

        // Act & Assert
        $this->assertTrue($name1->equals($name2));
        $this->assertTrue($name2->equals($name1));
    }

    public function test_equals_returns_false_for_different_values(): void
    {
        // Arrange
        $name1 = CookieName::fromString('Chocolate Chip');
        $name2 = CookieName::fromString('Oatmeal Raisin');

        // Act & Assert
        $this->assertFalse($name1->equals($name2));
        $this->assertFalse($name2->equals($name1));
    }

    public function test_equals_is_case_sensitive(): void
    {
        // Arrange
        $name1 = CookieName::fromString('Chocolate Chip');
        $name2 = CookieName::fromString('chocolate chip');

        // Act & Assert
        $this->assertFalse($name1->equals($name2));
    }

    public function test_to_string_returns_value(): void
    {
        // Arrange
        $cookieName = CookieName::fromString('Peanut Butter');

        // Act
        $stringValue = (string) $cookieName;

        // Assert
        $this->assertEquals('Peanut Butter', $stringValue);
    }

    public function test_is_immutable(): void
    {
        // Arrange
        $cookieName = CookieName::fromString('Original Name');
        $originalValue = $cookieName->getValue();

        // Act - Try to create new instance (value objects are immutable)
        $newCookieName = CookieName::fromString('New Name');

        // Assert - Original unchanged
        $this->assertEquals('Original Name', $cookieName->getValue());
        $this->assertEquals($originalValue, $cookieName->getValue());
        $this->assertNotEquals($newCookieName->getValue(), $cookieName->getValue());
    }

    // ==================== EDGE CASE TESTS ====================

    public function test_preserves_internal_whitespace(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString('Double  Chocolate    Chip');

        // Assert - Internal spaces preserved
        $this->assertEquals('Double  Chocolate    Chip', $cookieName->getValue());
    }

    public function test_handles_newlines_in_name(): void
    {
        // Arrange & Act
        $cookieName = CookieName::fromString("Multi\nLine\nCookie");

        // Assert
        $this->assertEquals("Multi\nLine\nCookie", $cookieName->getValue());
    }
}

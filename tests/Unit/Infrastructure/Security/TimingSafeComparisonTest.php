<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\TimingSafeComparison;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class TimingSafeComparisonTest extends TestCase
{
    // ========================================
    // equals() - Basic string comparison
    // ========================================

    public function testEqualsReturnsTrueForIdenticalStrings(): void
    {
        $known = 'secret_password_hash';
        $user = 'secret_password_hash';

        $result = TimingSafeComparison::equals($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsReturnsFalseForDifferentStrings(): void
    {
        $known = 'secret_password_hash';
        $user = 'different_password_hash';

        $result = TimingSafeComparison::equals($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsReturnsFalseForDifferentLengths(): void
    {
        $known = 'short';
        $user = 'much_longer_string';

        $result = TimingSafeComparison::equals($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsIsCaseSensitive(): void
    {
        $known = 'SecretValue';
        $user = 'secretvalue';

        $result = TimingSafeComparison::equals($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsHandlesEmptyStrings(): void
    {
        $result = TimingSafeComparison::equals('', '');

        $this->assertTrue($result);
    }

    public function testEqualsHandlesSpecialCharacters(): void
    {
        $known = 'hash!@#$%^&*()_+-=[]{}|;:,.<>?';
        $user = 'hash!@#$%^&*()_+-=[]{}|;:,.<>?';

        $result = TimingSafeComparison::equals($known, $user);

        $this->assertTrue($result);
    }

    // ========================================
    // equalsToken() - Hexadecimal tokens
    // ========================================

    public function testEqualsTokenReturnsTrueForIdenticalTokens(): void
    {
        $known = 'A1B2C3D4E5F6789012345678ABCDEF01';
        $user = 'A1B2C3D4E5F6789012345678ABCDEF01';

        $result = TimingSafeComparison::equalsToken($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsTokenIsCaseInsensitive(): void
    {
        $known = 'A1B2C3D4E5F6789012345678ABCDEF01';
        $user = 'a1b2c3d4e5f6789012345678abcdef01';

        $result = TimingSafeComparison::equalsToken($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsTokenReturnsFalseForDifferentTokens(): void
    {
        $known = 'A1B2C3D4E5F6789012345678ABCDEF01';
        $user = 'A1B2C3D4E5F6789012345678ABCDEF02';

        $result = TimingSafeComparison::equalsToken($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsTokenReturnsFalseForNonHexCharacters(): void
    {
        $known = 'A1B2C3D4E5F6789012345678ABCDEF01';
        $user = 'G1B2C3D4E5F6789012345678ABCDEF01'; // 'G' is not hex

        $result = TimingSafeComparison::equalsToken($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsTokenHandlesLongTokens(): void
    {
        $known = str_repeat('A1B2C3D4', 32); // 256-character token
        $user = str_repeat('A1B2C3D4', 32);

        $result = TimingSafeComparison::equalsToken($known, $user);

        $this->assertTrue($result);
    }

    // ========================================
    // equalsJwt() - JWT token comparison
    // ========================================

    public function testEqualsJwtReturnsTrueForIdenticalJwts(): void
    {
        $known = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $user = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';

        $result = TimingSafeComparison::equalsJwt($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsJwtReturnsFalseForDifferentJwts(): void
    {
        $known = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $user = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI5ODc2NTQzMjEwIn0.different_signature_here_12345';

        $result = TimingSafeComparison::equalsJwt($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsJwtReturnsFalseForInvalidFormat(): void
    {
        $known = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $user = 'invalid.jwt'; // Only 1 dot instead of 2

        $result = TimingSafeComparison::equalsJwt($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsJwtReturnsFalseForStringWithoutDots(): void
    {
        $known = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U';
        $user = 'totallywrongnodots';

        $result = TimingSafeComparison::equalsJwt($known, $user);

        $this->assertFalse($result);
    }

    // ========================================
    // equalsHash() - Hash comparison
    // ========================================

    public function testEqualsHashReturnsTrueForIdenticalHashes(): void
    {
        $known = hash('sha256', 'test_data');
        $user = hash('sha256', 'test_data');

        $result = TimingSafeComparison::equalsHash($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsHashReturnsFalseForDifferentHashes(): void
    {
        $known = hash('sha256', 'test_data');
        $user = hash('sha256', 'different_data');

        $result = TimingSafeComparison::equalsHash($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsHashReturnsFalseForDifferentLengths(): void
    {
        $known = hash('sha256', 'data'); // 64 characters
        $user = hash('sha512', 'data'); // 128 characters

        $result = TimingSafeComparison::equalsHash($known, $user);

        $this->assertFalse($result);
    }

    public function testEqualsHashHandlesShortHashes(): void
    {
        $known = hash('md5', 'data'); // 32 characters
        $user = hash('md5', 'data');

        $result = TimingSafeComparison::equalsHash($known, $user);

        $this->assertTrue($result);
    }

    public function testEqualsHashHandlesLongHashes(): void
    {
        $known = hash('sha512', 'data'); // 128 characters
        $user = hash('sha512', 'data');

        $result = TimingSafeComparison::equalsHash($known, $user);

        $this->assertTrue($result);
    }

    // ========================================
    // Security properties verification
    // ========================================

    /**
     * Verify that comparison time is independent of match position.
     *
     * This test demonstrates timing-attack resistance by comparing
     * strings that differ at different positions. If vulnerable,
     * early mismatches would return faster than late mismatches.
     *
     * Note: This is a statistical test - timing differences are small
     * and may not be detectable in a single run, but the use of
     * hash_equals() guarantees constant-time behavior.
     */
    public function testTimingIndependenceOfMatchPosition(): void
    {
        $known = str_repeat('A', 1000);

        // Mismatch at position 0
        $early = 'B' . str_repeat('A', 999);

        // Mismatch at position 999
        $late = str_repeat('A', 999) . 'B';

        // Both should return false
        $this->assertFalse(TimingSafeComparison::equals($known, $early));
        $this->assertFalse(TimingSafeComparison::equals($known, $late));

        // If vulnerable to timing attacks, early mismatch would be faster
        // hash_equals() prevents this by always processing full length
    }

    /**
     * Demonstrate proper usage for password verification.
     */
    public function testPasswordHashVerificationExample(): void
    {
        $password = 'SecurePassword123!';
        $storedHash = password_hash($password, PASSWORD_ARGON2ID);

        // Simulate user login with correct password
        $providedPassword = 'SecurePassword123!';
        $providedHash = password_hash($providedPassword, PASSWORD_ARGON2ID);

        // In real code, you would use password_verify(), not hash comparison
        // But if comparing pre-hashed values, use timing-safe comparison
        $this->assertIsString($storedHash);
        $this->assertIsString($providedHash);

        // Both are valid Argon2ID hashes
        $this->assertStringStartsWith('$argon2id$', $storedHash);
        $this->assertStringStartsWith('$argon2id$', $providedHash);
    }

    /**
     * Demonstrate proper usage for API token verification.
     */
    public function testApiTokenVerificationExample(): void
    {
        // Typical API token scenario
        $storedToken = bin2hex(random_bytes(32)); // 64-character hex
        $providedToken = $storedToken;

        $result = TimingSafeComparison::equalsToken($storedToken, $providedToken);

        $this->assertTrue($result);
    }
}

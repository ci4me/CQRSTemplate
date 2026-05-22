<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\Shared\Exceptions\ValidationException;
use CodeIgniter\Test\CIUnitTestCase;

final class HashedPasswordTest extends CIUnitTestCase
{
    public function testPasswordIsHashedWithArgon2ID(): void
    {
        $password = HashedPassword::fromPlaintext('SecurePassword123!');
        $hash = $password->getHash();

        $this->assertStringStartsWith('$argon2id$', $hash);
    }

    public function testCorrectPasswordVerifiesSuccessfully(): void
    {
        $plaintext = 'SecurePassword123!';
        $password = HashedPassword::fromPlaintext($plaintext);

        $this->assertTrue($password->verify($plaintext));
    }

    public function testIncorrectPasswordFailsVerification(): void
    {
        $password = HashedPassword::fromPlaintext('CorrectPassword123!');

        $this->assertFalse($password->verify('WrongPassword456!'));
    }

    public function testEmptyPasswordThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        HashedPassword::fromPlaintext('');
    }

    public function testPasswordTooShortThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        HashedPassword::fromPlaintext('short');
    }

    public function testFromHashCreatesInstanceFromExistingHash(): void
    {
        $hash = password_hash('TestPassword123', PASSWORD_ARGON2ID);
        $password = HashedPassword::fromHash($hash);

        $this->assertSame($hash, $password->getHash());
    }

    public function testVerificationIsTimingSafe(): void
    {
        $password = HashedPassword::fromPlaintext('TestPassword123!');

        // Multiple verifications should take similar time (timing-attack safe)
        $start1 = microtime(true);
        $password->verify('WrongPassword1!');
        $time1 = microtime(true) - $start1;

        $start2 = microtime(true);
        $password->verify('WrongPassword2!');
        $time2 = microtime(true) - $start2;

        // Times should be similar (within 50% margin)
        $this->assertLessThan($time1 * 1.5, $time2);
    }

    public function testNeedsRehashReturnsFalseForCurrentAlgorithm(): void
    {
        $password = HashedPassword::fromPlaintext('SecureP@ssw0rd!');

        $this->assertFalse($password->needsRehash());
    }
}

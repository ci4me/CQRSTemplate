<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Services;

use App\Infrastructure\Auth\Services\PasswordHashingService;
use Tests\Support\UnitTestCase;

/**
 * The service is a thin wrapper over password_hash/password_verify, but its
 * contract still matters: it must
 *  - use a modern algorithm (argon2id, not bcrypt or plain)
 *  - never return the plaintext
 *  - produce a hash that verify() can round-trip
 *  - produce DIFFERENT outputs for the same input (random salt)
 *  - reject mismatched passwords
 */
final class PasswordHashingServiceTest extends UnitTestCase
{
    public function test_hash_does_not_leak_plaintext(): void
    {
        $service = new PasswordHashingService();
        $hash = $service->hash('My-Secret-Password-1!');

        $this->assertNotSame('My-Secret-Password-1!', $hash);
        $this->assertStringNotContainsString('My-Secret-Password-1!', $hash);
    }

    public function test_hash_uses_argon2id(): void
    {
        $service = new PasswordHashingService();
        $hash = $service->hash('Some-Strong-Password-1!');

        // password_get_info reads the algorithm prefix; argon2id hashes start
        // with $argon2id$ — anything else means the algo silently changed.
        $info = password_get_info($hash);
        $this->assertSame(PASSWORD_ARGON2ID, $info['algo']);
    }

    public function test_hash_produces_different_outputs_for_identical_input(): void
    {
        $service = new PasswordHashingService();
        $a = $service->hash('Same-Password-1!');
        $b = $service->hash('Same-Password-1!');

        $this->assertNotSame($a, $b, 'hashes must use a random salt');
    }

    public function test_verify_accepts_correct_password(): void
    {
        $service = new PasswordHashingService();
        $hash = $service->hash('My-Verify-Password-1!');

        $this->assertTrue($service->verify('My-Verify-Password-1!', $hash));
    }

    public function test_verify_rejects_wrong_password(): void
    {
        $service = new PasswordHashingService();
        $hash = $service->hash('Correct-Password-1!');

        $this->assertFalse($service->verify('Wrong-Password-1!', $hash));
    }

    public function test_verify_rejects_empty_plaintext(): void
    {
        $service = new PasswordHashingService();
        $hash = $service->hash('Real-Password-1!');

        $this->assertFalse($service->verify('', $hash));
    }
}

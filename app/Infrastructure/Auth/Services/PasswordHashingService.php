<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Ports\PasswordHasherInterface;

/**
 * PasswordHashingService.
 */
final readonly class PasswordHashingService implements PasswordHasherInterface
{
    /**
     * hash.
     *
     * @param string $plaintext
     * @return string
     */
    public function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID);
    }

    /**
     * verify.
     *
     * @param string $plaintext
     * @param string $hash
     * @return bool
     */
    public function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }
}

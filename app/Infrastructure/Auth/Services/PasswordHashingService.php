<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Ports\PasswordHasherInterface;

final readonly class PasswordHashingService implements PasswordHasherInterface
{
    public function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }
}

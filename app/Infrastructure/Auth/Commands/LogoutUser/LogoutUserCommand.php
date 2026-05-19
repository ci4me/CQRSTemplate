<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LogoutUser;

final readonly class LogoutUserCommand
{
    public function __construct(
        public string $token,
        public ?string $refreshToken = null,
        public ?int $userId = null
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LoginUser;

final readonly class LoginUserCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LogoutUser;

/**
 * LogoutUserCommand.
 */
final readonly class LogoutUserCommand
{
    /**
     * __construct.
     *
     * @param string      $token
     * @param string|null $refreshToken
     * @param int|null    $userId
     */
    public function __construct(
        public string $token,
        public ?string $refreshToken = null,
        public ?int $userId = null
    ) {
    }
}

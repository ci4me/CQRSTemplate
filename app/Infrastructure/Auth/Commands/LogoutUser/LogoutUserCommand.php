<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LogoutUser;

/**
 * LogoutUserCommand.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
final readonly class LogoutUserCommand
{
    /**
     * __construct.
     *
     * @param string      $token
     * @param string|null $refreshToken
     * @param int|null    $userId
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        public string $token,
        public ?string $refreshToken = null,
        public ?int $userId = null
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LoginUser;

/**
 * LoginUserCommand.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
final readonly class LoginUserCommand
{
    /**
     * __construct.
     *
     * @param string      $email
     * @param string      $password
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {
    }
}

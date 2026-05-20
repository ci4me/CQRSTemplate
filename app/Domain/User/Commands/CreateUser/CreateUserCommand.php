<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\CreateUser;

/**
 * Command to create a new user (admin operation).
 *
 * Unlike RegisterUserCommand (self-registration, customer-only),
 * this command allows creating users with any valid role.
 * Intended for use by administrators.
 *
 * @package App\Domain\User\Commands\CreateUser
 */
final readonly class CreateUserCommand
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role = 'customer',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserByEmail;

/**
 * Query to retrieve a single User by email address.
 *
 * Commonly used for login/authentication flows and email existence checks.
 */
final readonly class GetUserByEmailQuery
{
    /**
     * @param string $email The email address of the user to retrieve
     */
    public function __construct(
        public string $email
    ) {
    }
}

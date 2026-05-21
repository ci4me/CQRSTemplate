<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\UpdateUser;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to update an existing user's information.
 *
 * Authorisation contract:
 * - Anyone can change a user's name/email (subject to upstream filters).
 * - Only admins may change `role` or `status`. The {@see UpdateUserHandler}
 *   rejects the command when a non-admin actor supplies a different role or
 *   status than the persisted value. `$role` / `$status` MUST always reflect
 *   the current value when the actor is not an admin — passing `null` would
 *   make the contract ambiguous and is therefore not supported.
 *
 * The handler also enforces existence and email-uniqueness.
 *
 * @package App\Domain\User\Commands\UpdateUser
 */
final readonly class UpdateUserCommand
{
    /**
     * __construct.
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
        public Actor $updatedBy
    ) {
    }
}

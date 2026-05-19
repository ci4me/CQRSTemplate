<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\UpdateUser;

/**
 * Command to update an existing user's information.
 *
 * This command represents the intent to modify user data including
 * profile information, role, and account status.
 *
 * Business Rules:
 * - User must exist
 * - Email must be unique (excluding current user)
 * - Only admins can change roles
 * - Cannot update password (use ChangeUserPasswordCommand instead)
 *
 * @package App\Domain\User\Commands\UpdateUser
 */
final readonly class UpdateUserCommand
{
    /**
     * @param int $userId User ID to update
     * @param string $name User's full name
     * @param string $email User's email address
     * @param string $role User role (admin, customer)
     * @param string $status Account status (active, inactive)
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public string $role,
        public string $status
    ) {
    }
}

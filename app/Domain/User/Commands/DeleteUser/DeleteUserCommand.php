<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\DeleteUser;

/**
 * Command to soft delete a user.
 *
 * This command performs a soft delete by setting the deleted_at timestamp.
 * The user data is preserved for audit purposes.
 *
 * Business Rules:
 * - User must exist
 * - Admin cannot delete their own account (prevent lockout)
 * - Only admins can delete users
 *
 * @package App\Domain\User\Commands\DeleteUser
 */
final readonly class DeleteUserCommand
{
    /**
     * @param int $userId User ID to delete
     */
    public function __construct(
        public int $userId
    ) {
    }
}

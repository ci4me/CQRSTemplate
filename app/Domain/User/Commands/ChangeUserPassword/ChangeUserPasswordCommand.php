<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\ChangeUserPassword;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command for admin to reset a user's password.
 *
 * This is for administrative password resets, not user self-service.
 * The new password will be validated against complexity requirements.
 *
 * Security Considerations:
 * - Only admins can change passwords
 * - Password complexity enforced (12+ chars, mixed case, digit, special char)
 * - Password change is logged for security audit
 * - User should be notified of password change
 *
 * @package App\Domain\User\Commands\ChangeUserPassword
 */
final readonly class ChangeUserPasswordCommand
{
    /**
     * @param int    $userId      User ID whose password to change
     * @param string $newPassword New plaintext password (will be hashed)
     * @param Actor  $changedBy   Authenticated actor performing the change
     */
    public function __construct(
        public int $userId,
        public string $newPassword,
        public Actor $changedBy
    ) {
    }
}

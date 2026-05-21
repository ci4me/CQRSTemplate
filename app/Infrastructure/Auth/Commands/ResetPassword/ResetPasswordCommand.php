<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\ResetPassword;

/**
 * Reset Password Command.
 *
 * Completes password reset flow using token from email.
 *
 * @package App\Infrastructure\Auth\Commands\ResetPassword
 */
final readonly class ResetPasswordCommand
{
    /**
     * __construct.
     *
     * @param string $token
     * @param string $newPassword
     */
    public function __construct(
        public string $token,
        public string $newPassword
    ) {
    }
}

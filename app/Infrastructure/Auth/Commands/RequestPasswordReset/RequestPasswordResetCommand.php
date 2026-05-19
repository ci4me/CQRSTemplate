<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\RequestPasswordReset;

/**
 * Request Password Reset Command.
 *
 * Initiates password reset flow by generating token and sending email.
 *
 * @package App\Infrastructure\Auth\Commands\RequestPasswordReset
 */
final readonly class RequestPasswordResetCommand
{
    public function __construct(
        public string $email
    ) {
    }
}

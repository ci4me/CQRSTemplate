<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\RegisterUser;

/**
 * Command to register a new User.
 *
 * Commands represent the INTENT to perform an action.
 * This command contains all data needed to register a user with email,
 * password, and role assignment.
 *
 * Commands are:
 * - Immutable DTOs (Data Transfer Objects)
 * - Named in imperative (RegisterUser, not UserRegistered)
 * - Validated by their handlers
 * - Do not contain business logic
 *
 * Business Context:
 * - User registration is the entry point for new users in the system
 * - Email uniqueness will be validated by the handler
 * - Password will be hashed by the handler before persistence
 * - Role determines the user's permission level in the system
 *
 * Security Considerations:
 * - Password is passed as plain text in command (will be hashed in handler)
 * - Email will be normalized and validated in handler
 * - Role must be a valid UserRole enum value
 *
 * @package App\Domain\User\Commands\RegisterUser
 */
final readonly class RegisterUserCommand
{
    /**
     * Create a new RegisterUserCommand.
     *
     * @param string $name     The user's full name
     * @param string $email    The user's email address (will be validated and normalized)
     * @param string $password The user's plain-text password (will be hashed by handler)
     * @param string $role     The user's role (must match UserRole enum values: 'admin', 'customer', 'guest')
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role
    ) {
    }
}

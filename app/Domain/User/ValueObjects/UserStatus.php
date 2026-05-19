<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

/**
 * Value Object representing User account status.
 *
 * This enum defines the possible states of a user account:
 * - Active: User can access the system normally
 * - Inactive: User account is disabled (cannot login)
 * - Suspended: User account is temporarily suspended (e.g., due to violation)
 * - PendingVerification: User registered but hasn't verified email/phone
 *
 * Why an Enum:
 * - Type-safe status representation (prevents invalid states)
 * - Self-documenting code (clear intent)
 * - Prevents magic strings throughout the codebase
 * - Enables exhaustive pattern matching in PHP 8.1+
 *
 * Immutability:
 * Enums are inherently immutable in PHP 8.1+. To change a user's status,
 * assign a different enum case.
 *
 * Usage Example:
 * ```php
 * $status = UserStatus::Active;
 * if ($status === UserStatus::Active) {
 *     // User can access the system
 * }
 *
 * // Pattern matching (PHP 8.1+)
 * $message = match($status) {
 *     UserStatus::Active => 'Account is active',
 *     UserStatus::Inactive => 'Account is disabled',
 *     UserStatus::Suspended => 'Account is suspended',
 *     UserStatus::PendingVerification => 'Please verify your email',
 * };
 * ```
 *
 * Serena Optimization:
 * - Clear, discoverable enum cases
 * - Single responsibility (represents user status only)
 * - Type-safe (no string comparisons needed)
 * - Self-documenting (enum name reveals purpose)
 *
 * @package App\Domain\User\ValueObjects
 */
enum UserStatus: string
{
    /**
     * User account is active and can access the system.
     */
    case Active = 'active';

    /**
     * User account is inactive and cannot login.
     */
    case Inactive = 'inactive';

    /**
     * User account is temporarily suspended.
     */
    case Suspended = 'suspended';

    /**
     * User has registered but not yet verified their account.
     */
    case PendingVerification = 'pending_verification';

    /**
     * Get human-readable status name.
     *
     * @return string The status display name
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Suspended => 'Suspended',
            self::PendingVerification => 'Pending Verification',
        };
    }

    /**
     * Check if user can login with this status.
     *
     * @return bool True if status allows login
     */
    public function canLogin(): bool
    {
        return $this === self::Active;
    }

    /**
     * Check if status requires verification.
     *
     * @return bool True if pending verification
     */
    public function requiresVerification(): bool
    {
        return $this === self::PendingVerification;
    }

    /**
     * Get all available statuses.
     *
     * @return array<string> Array of status values
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $status): string => $status->value,
            self::cases()
        );
    }
}

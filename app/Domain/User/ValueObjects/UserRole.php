<?php

declare(strict_types=1);

namespace App\Domain\User\ValueObjects;

/**
 * User Role Enum - Represents the role/permission level of a user.
 *
 * Business Rules:
 * - Admin: Full system access, can manage all users and data
 * - Customer: Standard user with basic permissions
 * - Guest: Limited access, typically unauthenticated or trial users
 *
 * Why an Enum for User Role:
 * - Type-safe representation of user roles
 * - Prevents invalid role values at compile time
 * - Self-documenting code (UserRole::Admin vs string 'admin')
 * - Enables exhaustive pattern matching in switch statements
 * - IDE autocomplete support for available roles
 *
 * Immutability:
 * Enums are inherently immutable. Once a UserRole case is defined,
 * it cannot be modified.
 *
 * Usage Example:
 * ```php
 * $role = UserRole::Admin;
 * $role->name; // "Admin"
 * $role->value; // "admin" (backed enum)
 *
 * // Pattern matching
 * match($role) {
 *     UserRole::Admin => 'Full access',
 *     UserRole::Customer => 'Basic access',
 *     UserRole::Guest => 'Limited access',
 * };
 * ```
 *
 * Serena Optimization:
 * - Clear, discoverable enum cases
 * - Single responsibility (role representation)
 * - No complex logic (pure data structure)
 * - Self-documenting names
 *
 * @package App\Domain\User\ValueObjects
 */
enum UserRole: string
{
    /**
     * Administrator role with full system permissions.
     */
    case Admin = 'admin';

    /**
     * Standard customer role with basic permissions.
     */
    case Customer = 'customer';

    /**
     * Guest role with limited permissions.
     */
    case Guest = 'guest';

    /**
     * Get human-readable role name for display purposes.
     *
     * @return string The display name for this role
     */
    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Customer => 'Customer',
            self::Guest => 'Guest',
        };
    }

    /**
     * Check if this role has administrator privileges.
     *
     * @return bool True if role is Admin
     */
    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /**
     * Check if this role is a standard customer.
     *
     * @return bool True if role is Customer
     */
    public function isCustomer(): bool
    {
        return $this === self::Customer;
    }

    /**
     * Check if this role is a guest.
     *
     * @return bool True if role is Guest
     */
    public function isGuest(): bool
    {
        return $this === self::Guest;
    }

    /**
     * Get role description explaining permissions.
     *
     * @return string Description of role permissions
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Admin => 'Full system access with all permissions',
            self::Customer => 'Standard user with basic permissions',
            self::Guest => 'Limited access for unauthenticated users',
        };
    }

    /**
     * Get all available role values as strings.
     *
     * @return array<string> Array of role values ['admin', 'customer', 'guest']
     */
    public static function values(): array
    {
        return array_map(
            static fn(self $role): string => $role->value,
            self::cases()
        );
    }
}

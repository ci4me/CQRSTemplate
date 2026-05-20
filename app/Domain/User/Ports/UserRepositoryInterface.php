<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;

/**
 * Repository interface for User persistence.
 *
 * This interface enables:
 * - Dependency inversion (handlers depend on interface, not implementation)
 * - Easy mocking in unit tests
 * - Potential for multiple implementations (different databases, caching, etc.)
 *
 * @package App\Domain\User\Ports
 */
interface UserRepositoryInterface
{
    /**
     * Save a new user.
     *
     * @param User $user The user entity to persist
     * @return int The newly created user ID
     */
    public function save(User $user): int;

    /**
     * Find a user by ID.
     *
     * @param int $id User ID
     * @return User|null The user entity or null if not found
     */
    public function findById(int $id): ?User;

    /**
     * Find a user by email address.
     *
     * @param Email $email The email to search for
     * @return User|null The user entity or null if not found
     */
    public function findByEmail(Email $email): ?User;

    /**
     * Update an existing user.
     *
     * @param User $user The user entity with updated values
     * @return bool True if update was successful
     */
    public function update(User $user): bool;

    /**
     * Soft delete a user.
     *
     * @param int $id User ID to delete
     * @return bool True if deletion was successful
     */
    public function delete(int $id): bool;

    /**
     * Find users with pagination and optional filters.
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @param bool $includeInactive Include soft-deleted users
     * @param string $searchTerm Search in name and email
     * @param string|null $role Filter by role
     * @param string|null $status Filter by status
     * @return array{data: array<User>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function findPaginated(
        int $page,
        int $perPage,
        bool $includeInactive = false,
        string $searchTerm = '',
        ?string $role = null,
        ?string $status = null
    ): array;

    /**
     * Count total users (excluding deleted).
     *
     * @return int Total user count
     */
    public function countTotal(): int;

    /**
     * Count users by role.
     *
     * @param string $role Role to count (admin, customer)
     * @return int User count for role
     */
    public function countByRole(string $role): int;

    /**
     * Count users by status.
     *
     * @param string $status Status to count (active, inactive)
     * @return int User count for status
     */
    public function countByStatus(string $status): int;
}

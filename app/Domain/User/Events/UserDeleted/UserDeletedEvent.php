<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserDeleted;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event triggered when a user is soft deleted.
 *
 * This event is dispatched after successful user deletion (soft delete)
 * and enables audit logging and cleanup of related data.
 *
 * Event Data:
 * - userId: The deleted user's ID
 * - deletedBy: Admin ID who performed the deletion
 * - deletedAt: Timestamp of the deletion
 *
 * Subscribers:
 * - UserDeletedEventHandler: Logs the deletion for audit purposes
 *
 * @package App\Domain\User\Events\UserDeleted
 */
final readonly class UserDeletedEvent implements DomainEventInterface
{
    /**
     * @param int $userId User ID that was deleted
     * @param int $deletedBy Admin user ID who deleted
     * @param string $deletedAt ISO 8601 timestamp of deletion
     */
    public function __construct(
        public int $userId,
        public int $deletedBy,
        public string $deletedAt
    ) {
    }
}

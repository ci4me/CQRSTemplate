<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserUpdated;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event triggered when a user's information is updated.
 *
 * This event is dispatched after successful user updates and enables
 * audit logging, notifications, and synchronization with external systems.
 *
 * Event Data:
 * - userId: The updated user's ID
 * - updatedFields: Array of field names that were changed
 * - updatedAt: Timestamp of the update
 *
 * Subscribers:
 * - UserUpdatedEventHandler: Logs the update for audit purposes
 *
 * @package App\Domain\User\Events\UserUpdated
 */
final readonly class UserUpdatedEvent implements DomainEventInterface
{
    /**
     * @param int $userId User ID that was updated
     * @param array<string> $updatedFields List of fields that changed
     * @param string $updatedAt ISO 8601 timestamp of update
     */
    public function __construct(
        public int $userId,
        public array $updatedFields,
        public string $updatedAt
    ) {
    }
}

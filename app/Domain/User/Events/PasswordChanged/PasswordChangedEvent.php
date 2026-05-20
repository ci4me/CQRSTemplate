<?php

declare(strict_types=1);

namespace App\Domain\User\Events\PasswordChanged;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event triggered when a user's password is changed by an admin.
 *
 * This is a SECURITY-CRITICAL event that must be logged for audit purposes.
 * Password changes represent potential security incidents and must be tracked.
 *
 * Security Considerations:
 * - This event is logged with SECURITY tag for audit systems
 * - User should be notified of password change via email
 * - Previous sessions should be invalidated
 * - Event includes correlation ID for tracing
 *
 * Event Data:
 * - userId: User whose password was changed
 * - changedBy: Admin who changed the password
 * - changedAt: Timestamp of the change
 *
 * Subscribers:
 * - PasswordChangedEventHandler: Security audit logging
 *
 * @package App\Domain\User\Events\PasswordChanged
 */
final readonly class PasswordChangedEvent implements DomainEventInterface
{
    /**
     * @param int    $userId    User ID whose password was changed
     * @param int    $changedBy Admin user ID who changed password
     * @param string $changedAt ISO 8601 timestamp of password change
     */
    public function __construct(
        public int $userId,
        public int $changedBy,
        public string $changedAt
    ) {
    }
}

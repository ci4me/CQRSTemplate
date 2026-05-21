<?php

declare(strict_types=1);

namespace App\Domain\User\Events\PasswordChanged;

use Psr\Log\LoggerInterface;

/**
 * Event handler for PasswordChangedEvent.
 *
 * This is a SECURITY-CRITICAL handler that logs all password changes.
 * Password changes are potential security incidents and must be tracked.
 *
 * Security Features:
 * - Logs with SECURITY tag for SIEM systems
 * - Includes admin ID who made the change
 * - Includes correlation ID for tracing
 * - Can trigger notifications to affected user
 *
 * Additional subscribers can be added for:
 * - Email notification to user
 * - Session invalidation
 * - Security alerts for suspicious activity
 *
 * @package App\Domain\User\Events\PasswordChanged
 */
final readonly class PasswordChangedEventHandler
{
    /**
     * __construct.
     */
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * __invoke.
     */
    public function __invoke(PasswordChangedEvent $event): void
    {
        $this->logger->info('Password changed', [
            'domain' => 'User',
            'event' => 'PasswordChangedEvent',
            'user_id' => $event->userId,
            'changed_by' => $event->changedBy,
            'changed_at' => $event->changedAt,
            'security' => 'CRITICAL',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserUpdated;

use Psr\Log\LoggerInterface;

/**
 * Event handler for UserUpdatedEvent.
 *
 * This handler logs user updates for audit purposes.
 * Additional subscribers can be added for:
 * - Email notifications
 * - External system synchronization
 * - Analytics tracking
 *
 * @package App\Domain\User\Events\UserUpdated
 */
final readonly class UserUpdatedEventHandler
{
    /**
     * __construct.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * __invoke.
     *
     * @param UserUpdatedEvent $event
     * @return void
     */
    public function __invoke(UserUpdatedEvent $event): void
    {
        $this->logger->info('User updated', [
            'domain' => 'User',
            'event' => 'UserUpdatedEvent',
            'user_id' => $event->userId,
            'updated_fields' => $event->updatedFields,
            'updated_at' => $event->updatedAt,
        ]);
    }
}

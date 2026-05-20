<?php

declare(strict_types=1);

namespace App\Domain\User\Events\UserDeleted;

use Psr\Log\LoggerInterface;

/**
 * Event handler for UserDeletedEvent.
 *
 * This handler logs user deletions for audit purposes.
 * Additional subscribers can be added for:
 * - Email notifications to user
 * - Cleanup of related data
 * - Compliance reporting
 *
 * @package App\Domain\User\Events\UserDeleted
 */
final readonly class UserDeletedEventHandler
{
    /**
     * __construct.
     *
     * @param LoggerInterface $logger
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * __invoke.
     *
     * @param UserDeletedEvent $event
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __invoke(UserDeletedEvent $event): void
    {
        $this->logger->info('User deleted', [
            'domain' => 'User',
            'event' => 'UserDeletedEvent',
            'user_id' => $event->userId,
            'deleted_by' => $event->deletedBy,
            'deleted_at' => $event->deletedAt,
        ]);
    }
}

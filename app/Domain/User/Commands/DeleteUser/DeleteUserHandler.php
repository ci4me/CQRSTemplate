<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\DeleteUser;

use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserDeleted\UserDeletedEvent;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Infrastructure\Bus\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for DeleteUserCommand.
 *
 * This handler performs a soft delete on a user account.
 * The user data is preserved for audit purposes.
 *
 * Business Rules Enforced:
 * - User must exist
 * - Admin cannot delete their own account (prevent lockout)
 * - Only admins can delete users (enforced by filter)
 *
 * Security Considerations:
 * - Soft delete preserves audit trail
 * - Self-deletion prevention protects system access
 * - All deletions are logged with admin ID
 *
 * @package App\Domain\User\Commands\DeleteUser
 */
final readonly class DeleteUserHandler
{
    /**
     * __construct.
     *
     * @param UserRepositoryInterface  $repository
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface          $logger
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * handle.
     *
     * @param DeleteUserCommand $command
     * @return void
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function handle(DeleteUserCommand $command): void
    {

        $this->logger->info('Deleting user', [
            'domain' => 'User',
            'command' => 'DeleteUserCommand',
            'user_id' => $command->userId,
        ]);

        try {
            // Business rule: admin cannot delete their own account (prevent lockout)
            if (!$command->deletedBy->isSystem() && $command->deletedBy->id === $command->userId) {
                $this->logger->warning('Self-deletion attempt blocked', [
                    'domain' => 'User',
                    'command' => 'DeleteUserCommand',
                    'user_id' => $command->userId,
                    'actor_id' => $command->deletedBy->id,
                ]);
                throw new \InvalidArgumentException(
                    'Administrators cannot delete their own account.',
                    ErrorCodes::USER_VALIDATION_NAME
                );
            }

            // Check user exists
            $user = $this->repository->findById($command->userId);
            if ($user === null) {
                $this->logger->error('User not found for deletion', [
                    'domain' => 'User',
                    'command' => 'DeleteUserCommand',
                    'user_id' => $command->userId,
                    'error_code' => ErrorCodes::USER_NOT_FOUND,
                ]);
                throw new \RuntimeException(
                    sprintf('User with ID %d not found', $command->userId),
                    ErrorCodes::USER_NOT_FOUND
                );
            }

            // Perform soft delete
            $result = $this->repository->delete($command->userId);

            if (!$result) {
                throw new \RuntimeException('Failed to delete user');
            }

            $this->logger->info('User deleted successfully', [
                'domain' => 'User',
                'command' => 'DeleteUserCommand',
                'user_id' => $command->userId,
            ]);

            // Dispatch event
            $event = new UserDeletedEvent(
                userId: $command->userId,
                deletedBy: $command->deletedBy->id,
                deletedAt: (new \DateTimeImmutable())->format('c')
            );
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete user', [
                'domain' => 'User',
                'command' => 'DeleteUserCommand',
                'user_id' => $command->userId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }
}

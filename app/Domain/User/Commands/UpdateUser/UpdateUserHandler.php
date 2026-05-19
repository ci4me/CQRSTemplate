<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\UpdateUser;

use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserUpdated\UserUpdatedEvent;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for UpdateUserCommand.
 *
 * This handler updates an existing user's information with proper
 * validation and authorization checks.
 *
 * Business Rules Enforced:
 * - User must exist
 * - Email must be unique (excluding current user)
 * - Only admins can change roles (checked in controller/filter)
 * - All changes are logged for audit
 *
 * Security Considerations:
 * - Role changes require admin authorization
 * - Email uniqueness prevents account conflicts
 * - All operations are logged with correlation ID
 *
 * @package App\Domain\User\Commands\UpdateUser
 */
final readonly class UpdateUserHandler
{
    public function __construct(
        private UserRepositoryInterface $repository,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    public function handle(UpdateUserCommand $command): void
    {

        $this->logger->info('Updating user', [
            'domain' => 'User',
            'command' => 'UpdateUserCommand',
            'user_id' => $command->userId,
        ]);

        try {
            // Check user exists
            $user = $this->repository->findById($command->userId);
            if ($user === null) {
                $this->logger->error('User not found for update', [
                    'domain' => 'User',
                    'command' => 'UpdateUserCommand',
                    'user_id' => $command->userId,
                    'error_code' => ErrorCodes::USER_NOT_FOUND,
                ]);
                throw new \RuntimeException(
                    sprintf('User with ID %d not found', $command->userId),
                    ErrorCodes::USER_NOT_FOUND
                );
            }

            // Validate email uniqueness (excluding current user)
            $email = Email::fromString($command->email);
            if ($email->getValue() !== $user->getEmail()->getValue()) {
                $existingUser = $this->repository->findByEmail($email);
                if ($existingUser !== null && $existingUser->getId() !== $command->userId) {
                    $this->logger->warning('Email already in use', [
                        'domain' => 'User',
                        'command' => 'UpdateUserCommand',
                        'email' => $command->email,
                        'error_code' => ErrorCodes::USER_VALIDATION_EMAIL,
                    ]);
                    throw new \RuntimeException(
                        'Email address is already in use',
                        ErrorCodes::USER_VALIDATION_EMAIL
                    );
                }
            }

            // Track changed fields
            $name = UserName::fromString($command->name);
            $updatedFields = [];
            if ($name->getValue() !== $user->getName()->getValue()) {
                $updatedFields[] = 'name';
            }
            if ($email->getValue() !== $user->getEmail()->getValue()) {
                $updatedFields[] = 'email';
            }
            if ($command->role !== $user->getRole()->value) {
                $updatedFields[] = 'role';
            }
            if ($command->status !== $user->getStatus()->value) {
                $updatedFields[] = 'status';
            }

            // Update user entity
            $user->update(
                $name,
                $email,
                UserRole::from($command->role),
                UserStatus::from($command->status)
            );

            // Save to repository
            $this->repository->update($user);

            $this->logger->info('User updated successfully', [
                'domain' => 'User',
                'command' => 'UpdateUserCommand',
                'user_id' => $command->userId,
                'updated_fields' => $updatedFields,
            ]);

            // Dispatch event
            $event = new UserUpdatedEvent(
                userId: $command->userId,
                updatedFields: $updatedFields,
                updatedAt: (new \DateTimeImmutable())->format('c')
            );
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update user', [
                'domain' => 'User',
                'command' => 'UpdateUserCommand',
                'user_id' => $command->userId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }
}

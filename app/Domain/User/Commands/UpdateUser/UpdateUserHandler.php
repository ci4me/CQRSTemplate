<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\UpdateUser;

use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserUpdated\UserUpdatedEvent;
use App\Domain\User\Ports\PermissionCheckerInterface;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
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
 * @implements CommandHandlerInterface<UpdateUserCommand, void>
 */
final readonly class UpdateUserHandler implements CommandHandlerInterface
{
    /**
     * __construct.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?PermissionCheckerInterface $permissions = null
    ) {
    }

    /**
     * handle.
     *
     * @param UpdateUserCommand $command
     * @throws \RuntimeException
     */
    public function handle(object $command): void
    {

        $this->logger->info('Updating user', [
            'domain' => 'User',
            'command' => 'UpdateUserCommand',
            'user_id' => $command->userId,
            'actor_id' => $command->updatedBy->id,
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

            // SECURITY: reject mass-assignment of role/status by non-admins.
            // The command always carries `role` and `status` (to keep the
            // contract simple and explicit) but only an admin may *change*
            // them. Non-admin callers MUST forward the persisted value
            // verbatim; anything else is treated as a privilege-escalation
            // attempt and refused.
            $this->assertRoleAndStatusChangesAllowed($command, $user);

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

    /**
     * Refuse role/status changes when the actor is not an admin.
     *
     * A non-admin "Update profile" call MUST be a no-op for privileged
     * fields. We don't allow the command to omit them (so the protocol
     * stays explicit), but we DO require that the values match what is
     * already in the database.
     *
     * @throws DomainException
     */
    private function assertRoleAndStatusChangesAllowed(
        UpdateUserCommand $command,
        \App\Domain\User\Entities\User $user
    ): void {
        $roleChanged = $command->role !== $user->getRole()->value;
        $statusChanged = $command->status !== $user->getStatus()->value;

        if (!$roleChanged && !$statusChanged) {
            return;
        }

        if ($this->actorIsAdmin($command)) {
            return;
        }

        $this->logger->warning('Refused mass-assignment of privileged fields', [
            'domain' => 'User',
            'command' => 'UpdateUserCommand',
            'user_id' => $command->userId,
            'actor_id' => $command->updatedBy->id,
            'role_change_attempted' => $roleChanged,
            'status_change_attempted' => $statusChanged,
            'error_code' => ErrorCodes::USER_BUSINESS_RULE_LOCKED,
            'security' => 'CRITICAL',
        ]);

        throw DomainException::businessRuleViolation(
            'Only administrators can change role or status.',
            sprintf('actor=%d target=%d', $command->updatedBy->id, $command->userId),
            ErrorCodes::USER_BUSINESS_RULE_LOCKED
        );
    }

    /**
     * actorIsAdmin.
     */
    private function actorIsAdmin(UpdateUserCommand $command): bool
    {
        $actor = $command->updatedBy;

        // System actor (background jobs, migrations) is trusted to change
        // anything; gating that is the responsibility of whatever queued
        // the command. This is intentionally separate from the request
        // path: ActorResolver is fail-closed so a system actor never
        // shows up for an HTTP request.
        if ($actor->isSystem()) {
            return true;
        }

        if ($this->permissions === null) {
            return false;
        }

        return $this->permissions->allows(
            $actor,
            \App\Domain\Shared\ValueObjects\Permission::fromString('user.manage')
        );
    }
}

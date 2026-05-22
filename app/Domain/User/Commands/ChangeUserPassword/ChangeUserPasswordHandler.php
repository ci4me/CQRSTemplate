<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\ChangeUserPassword;

use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\PasswordChanged\PasswordChangedEvent;
use App\Domain\User\Ports\SessionManagerInterface;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\Repositories\PasswordHistoryRepository;
use App\Domain\User\ValueObjects\HashedPassword;
use Psr\Log\LoggerInterface;

/**
 * Handler for ChangeUserPasswordCommand.
 *
 * This handler changes a user's password with full complexity validation.
 * This is for administrative password resets, not user self-service.
 *
 * Business Rules Enforced:
 * - User must exist
 * - Password must meet complexity requirements (enforced by HashedPassword)
 * - Password must not be reused (last 5 passwords)
 * - Only admins can change passwords (enforced by filter)
 *
 * Security Considerations:
 * - Password complexity: 12+ chars, uppercase, lowercase, digit, special char
 * - Password reuse prevention: Last 5 passwords tracked
 * - Password change is logged with SECURITY tag
 * - Password history stored securely (hashed)
 * - User should be notified via email
 * - Consider invalidating existing sessions
 *
 * @package App\Domain\User\Commands\ChangeUserPassword
 */
final readonly class ChangeUserPasswordHandler
{
    /**
     * __construct.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private PasswordHistoryRepository $passwordHistory,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ?SessionManagerInterface $sessionManager = null
    ) {
    }

    /**
     * handle.
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     */
    public function handle(ChangeUserPasswordCommand $command): void
    {

        $this->logger->info('Changing user password', [
            'domain' => 'User',
            'command' => 'ChangeUserPasswordCommand',
            'user_id' => $command->userId,
            'security' => 'CRITICAL',
        ]);

        try {
            // Check user exists
            $user = $this->repository->findById($command->userId);
            if ($user === null) {
                $this->logger->error('User not found for password change', [
                    'domain' => 'User',
                    'command' => 'ChangeUserPasswordCommand',
                    'user_id' => $command->userId,
                    'error_code' => ErrorCodes::USER_NOT_FOUND,
                    'security' => 'CRITICAL',
                ]);
                throw new \RuntimeException(
                    sprintf('User with ID %d not found', $command->userId),
                    ErrorCodes::USER_NOT_FOUND
                );
            }

            // SECURITY: Check password reuse (last 5 passwords)
            if ($this->passwordHistory->containsPassword($command->userId, $command->newPassword)) {
                $this->logger->warning('Password reuse attempt detected', [
                    'domain' => 'User',
                    'command' => 'ChangeUserPasswordCommand',
                    'user_id' => $command->userId,
                    'security' => 'CRITICAL',
                ]);
                throw new \InvalidArgumentException(
                    'Password has been used recently. Please choose a different password.',
                    ErrorCodes::USER_VALIDATION_PASSWORD
                );
            }

            // Hash password with complexity validation
            $hashedPassword = HashedPassword::fromPlaintext($command->newPassword);

            // Update password
            $user->changePassword($hashedPassword);

            // Save to repository
            $this->repository->update($user);

            // Store password in history
            $this->passwordHistory->store($command->userId, $hashedPassword->getHash());

            $this->logger->info('Password changed successfully', [
                'domain' => 'User',
                'command' => 'ChangeUserPasswordCommand',
                'user_id' => $command->userId,
                'security' => 'CRITICAL',
            ]);

            // SECURITY (A4): revoke every active session for the affected user
            // so that any previously-issued JWT/web session cannot be used.
            $this->sessionManager?->revokeAllUserSessions($command->userId);

            // Dispatch security event
            $event = new PasswordChangedEvent(
                userId: $command->userId,
                changedBy: $command->changedBy->id,
                changedAt: (new \DateTimeImmutable())->format('c')
            );
            $this->eventDispatcher->dispatch($event);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to change password', [
                'domain' => 'User',
                'command' => 'ChangeUserPasswordCommand',
                'user_id' => $command->userId,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
                'security' => 'CRITICAL',
            ]);
            throw $e;
        }
    }
}

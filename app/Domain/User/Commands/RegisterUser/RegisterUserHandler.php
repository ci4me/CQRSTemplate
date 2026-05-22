<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\RegisterUser;

use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\User\Entities\User;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserRegistered\UserRegisteredEvent;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use Psr\Log\LoggerInterface;

/**
 * RegisterUserHandler.
 *
 * @implements CommandHandlerInterface<RegisterUserCommand, int>
 */
final readonly class RegisterUserHandler implements CommandHandlerInterface
{
    /**
     * __construct.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * handle.
     *
     * @param RegisterUserCommand $command
     * @throws DomainException
     */
    public function handle(object $command): int
    {
        $startTime = microtime(true);

        $this->logger->info('Registering new user', [
            'domain' => 'User',
            'command' => 'RegisterUserCommand',
            'email' => $command->email,
        ]);

        try {
            $name = UserName::fromString($command->name);
            $email = Email::fromString($command->email);

            $this->checkEmailUniqueness($email);

            // SECURITY: Use HashedPassword::fromPlaintext() to enforce complexity validation
            // This validates password meets OWASP requirements before hashing
            $hashedPassword = HashedPassword::fromPlaintext($command->password);

            // SECURITY FIX (CVSS 9.0): Hardcode role to 'customer' for self-registration
            // Admin accounts must be created by existing administrators only
            // Reject any attempt to register with admin role
            if ($command->role === 'admin') {
                throw DomainException::businessRuleViolation(
                    'Admin role cannot be self-assigned',
                    'attempted_role: admin',
                    ErrorCodes::USER_BUSINESS_RULE_INVALID_ROLE_ASSIGNMENT
                );
            }

            $role = UserRole::Customer; // Always customer for self-registration

            $user = User::create($name, $email, $hashedPassword, $role);

            $userId = $this->repository->save($user);

            $event = new UserRegisteredEvent(
                userId: $userId,
                email: $email->getValue(),
                registeredAt: new \DateTimeImmutable()
            );
            $this->eventDispatcher->dispatch($event);

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('User registered successfully', [
                'domain' => 'User',
                'command' => 'RegisterUserCommand',
                'user_id' => $userId,
                'duration_ms' => round($duration, 2),
            ]);

            return $userId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to register user', [
                'domain' => 'User',
                'command' => 'RegisterUserCommand',
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * checkEmailUniqueness.
     *
     * @throws DomainException
     */
    private function checkEmailUniqueness(Email $email): void
    {
        $existing = $this->repository->findByEmail($email);
        if ($existing !== null) {
            // SECURITY: Use generic error message to prevent user enumeration
            // Log actual reason internally for audit, but return generic message to user
            $this->logger->warning('Registration failed - email already exists', [
                'domain' => 'User',
                'command' => 'RegisterUserCommand',
                'email' => $email->getValue(),
                'error_code' => ErrorCodes::USER_BUSINESS_RULE_EMAIL_ALREADY_EXISTS,
            ]);

            // Perform timing-safe dummy operation to prevent timing attacks
            password_hash('dummy-password-to-normalize-timing', PASSWORD_ARGON2ID);

            throw DomainException::businessRuleViolation(
                'Registration failed. Please try again.',
                'registration_failed',
                ErrorCodes::USER_BUSINESS_RULE_EMAIL_ALREADY_EXISTS
            );
        }
    }
}

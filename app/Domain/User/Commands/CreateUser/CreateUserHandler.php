<?php

declare(strict_types=1);

namespace App\Domain\User\Commands\CreateUser;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\User\Entities\User;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserRegistered\UserRegisteredEvent;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Bus\EventDispatcherInterface;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Handler for CreateUserCommand (admin user creation).
 *
 * Unlike RegisterUserHandler, this handler accepts any valid role
 * from the UserRole enum, allowing administrators to create
 * admin, customer, or other role accounts.
 *
 * @package App\Domain\User\Commands\CreateUser
 */
final readonly class CreateUserHandler
{
    public function __construct(
        private UserRepository $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(CreateUserCommand $command): int
    {
        $startTime = microtime(true);

        $this->logger->info('Creating new user (admin)', [
            'domain' => 'User',
            'command' => 'CreateUserCommand',
            'email' => $command->email,
            'role' => $command->role,
        ]);

        try {
            $name = UserName::fromString($command->name);
            $email = Email::fromString($command->email);

            $this->checkEmailUniqueness($email);

            $hashedPassword = HashedPassword::fromPlaintext($command->password);

            $role = UserRole::from($command->role);

            $user = User::create($name, $email, $hashedPassword, $role);

            $userId = $this->repository->save($user);

            $event = new UserRegisteredEvent(
                userId: $userId,
                email: $email->getValue(),
                registeredAt: new \DateTimeImmutable()
            );
            $this->eventDispatcher->dispatch($event);

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('User created successfully (admin)', [
                'domain' => 'User',
                'command' => 'CreateUserCommand',
                'user_id' => $userId,
                'role' => $command->role,
                'duration_ms' => round($duration, 2),
            ]);

            return $userId;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create user', [
                'domain' => 'User',
                'command' => 'CreateUserCommand',
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function checkEmailUniqueness(Email $email): void
    {
        $existing = $this->repository->findByEmail($email);
        if ($existing !== null) {
            $this->logger->warning('User creation failed - email already exists', [
                'domain' => 'User',
                'command' => 'CreateUserCommand',
                'email' => $email->getValue(),
                'error_code' => ErrorCodes::USER_BUSINESS_RULE_EMAIL_ALREADY_EXISTS,
            ]);

            throw DomainException::businessRuleViolation(
                'A user with this email already exists.',
                'email_already_exists',
                ErrorCodes::USER_BUSINESS_RULE_EMAIL_ALREADY_EXISTS
            );
        }
    }
}

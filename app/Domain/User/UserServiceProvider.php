<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordCommand;
use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordHandler;
use App\Domain\User\Commands\CreateUser\CreateUserCommand;
use App\Domain\User\Commands\CreateUser\CreateUserHandler;
use App\Domain\User\Commands\DeleteUser\DeleteUserCommand;
use App\Domain\User\Commands\DeleteUser\DeleteUserHandler;
use App\Domain\User\Commands\RegisterUser\RegisterUserCommand;
use App\Domain\User\Commands\RegisterUser\RegisterUserHandler;
use App\Domain\User\Commands\UpdateUser\UpdateUserCommand;
use App\Domain\User\Commands\UpdateUser\UpdateUserHandler;
use App\Domain\User\Events\PasswordChanged\PasswordChangedEvent;
use App\Domain\User\Events\PasswordChanged\PasswordChangedEventHandler;
use App\Domain\User\Events\UserDeleted\UserDeletedEvent;
use App\Domain\User\Events\UserDeleted\UserDeletedEventHandler;
use App\Domain\User\Events\UserRegistered\UserRegisteredEvent;
use App\Domain\User\Events\UserRegistered\UserRegisteredEventHandler;
use App\Domain\User\Events\UserUpdated\UserUpdatedEvent;
use App\Domain\User\Events\UserUpdated\UserUpdatedEventHandler;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersHandler;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersQuery;
use App\Domain\User\Queries\GetUserByEmail\GetUserByEmailHandler;
use App\Domain\User\Queries\GetUserByEmail\GetUserByEmailQuery;
use App\Domain\User\Queries\GetUserById\GetUserByIdHandler;
use App\Domain\User\Queries\GetUserById\GetUserByIdQuery;
use App\Domain\User\Queries\SearchUsers\SearchUsersHandler;
use App\Domain\User\Queries\SearchUsers\SearchUsersQuery;
use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Persistence\Repositories\PasswordHistoryRepository;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * User Domain Service Provider.
 *
 * Registers all User domain handlers with the application buses.
 * Auto-discovered via #[DomainServiceProvider] attribute.
 */
#[DomainServiceProvider]
final class UserServiceProvider implements DomainServiceProviderInterface
{
    /**
     * @var array<string, object>
     */
    private array $repositories = [];

    public function registerCommands(CommandBus $commandBus): void
    {
        $repository = $this->getRepository('userRepository');
        $passwordHistory = $this->getRepository('passwordHistoryRepository');
        $eventDispatcher = $this->getRepository('eventDispatcher');
        $logger = $this->getRepository('logger');

        if (
            !$repository instanceof UserRepository
            || !$passwordHistory instanceof PasswordHistoryRepository
            || !$eventDispatcher instanceof EventDispatcher
            || !$logger instanceof LoggerInterface
        ) {
            throw new \RuntimeException('Invalid dependencies injected');
        }

        $commandBus->register(
            RegisterUserCommand::class,
            new RegisterUserHandler($repository, $eventDispatcher, $logger)
        );

        $commandBus->register(
            UpdateUserCommand::class,
            new UpdateUserHandler($repository, $eventDispatcher, $logger)
        );

        $commandBus->register(
            DeleteUserCommand::class,
            new DeleteUserHandler($repository, $eventDispatcher, $logger)
        );

        $commandBus->register(
            ChangeUserPasswordCommand::class,
            new ChangeUserPasswordHandler(
                $repository,
                $passwordHistory,
                $eventDispatcher,
                $logger,
                \Config\Services::sessionManagementService()
            )
        );

        $commandBus->register(
            CreateUserCommand::class,
            new CreateUserHandler($repository, $eventDispatcher, $logger)
        );
    }

    public function registerQueries(QueryBus $queryBus): void
    {
        $repository = $this->getRepository('userRepository');
        $logger = $this->getRepository('logger');
        $loggingConfig = $this->getRepository('loggingConfig');
        assert($loggingConfig instanceof Logging);

        if (!$repository instanceof UserRepository || !$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Invalid repository or logger injected');
        }

        $queryBus->register(
            GetUserByIdQuery::class,
            new GetUserByIdHandler($repository, $logger, $loggingConfig)
        );

        $queryBus->register(
            GetUserByEmailQuery::class,
            new GetUserByEmailHandler($repository, $logger, $loggingConfig)
        );

        $queryBus->register(
            GetAllUsersQuery::class,
            new GetAllUsersHandler($repository, $logger, $loggingConfig)
        );

        $queryBus->register(
            SearchUsersQuery::class,
            new SearchUsersHandler($repository, $logger, $loggingConfig)
        );
    }

    public function registerEvents(EventDispatcher $dispatcher): void
    {
        $logger = $this->getRepository('logger');

        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Invalid logger injected');
        }

        $dispatcher->subscribe(
            UserRegisteredEvent::class,
            new UserRegisteredEventHandler($logger)
        );

        $dispatcher->subscribe(
            UserUpdatedEvent::class,
            new UserUpdatedEventHandler($logger)
        );

        $dispatcher->subscribe(
            UserDeletedEvent::class,
            new UserDeletedEventHandler($logger)
        );

        $dispatcher->subscribe(
            PasswordChangedEvent::class,
            new PasswordChangedEventHandler($logger)
        );
    }

    /**
     * @return string[]
     */
    public function getRepositories(): array
    {
        return [
            'userRepository',
            'passwordHistoryRepository',
            'eventDispatcher',
            'passwordHasher',
            'logger',
            'loggingConfig',
        ];
    }

    /**
     * @param array<string, object> $repositories
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    private function getRepository(string $name): object
    {
        return $this->repositories[$name];
    }
}

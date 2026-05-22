<?php

declare(strict_types=1);

namespace App\Domain\User;

use App\Domain\Shared\Ports\LogConfigPort;
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
use App\Domain\User\Ports\PasswordHistoryRepositoryInterface;
use App\Domain\User\Ports\SessionManagerInterface;
use App\Domain\User\Ports\UserRepositoryInterface;
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
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;
use CodeIgniter\Router\RouteCollection;
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

    /**
     * registerCommands.
     *
     * @throws \RuntimeException
     */
    public function registerCommands(CommandBus $commandBus): void
    {
        $repository = $this->getRepository('userRepository');
        $passwordHistory = $this->getRepository('passwordHistoryRepository');
        $eventDispatcher = $this->getRepository('eventDispatcher');
        $logger = $this->getRepository('logger');
        $sessionManager = $this->getRepository('sessionManagementService');

        if (
            !$repository instanceof UserRepositoryInterface
            || !$passwordHistory instanceof PasswordHistoryRepositoryInterface
            || !$eventDispatcher instanceof EventDispatcher
            || !$logger instanceof LoggerInterface
            || !$sessionManager instanceof SessionManagerInterface
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
                $sessionManager
            )
        );

        $commandBus->register(
            CreateUserCommand::class,
            new CreateUserHandler($repository, $eventDispatcher, $logger)
        );
    }

    /**
     * registerQueries.
     *
     * @throws \RuntimeException
     */
    public function registerQueries(QueryBus $queryBus): void
    {
        $repository = $this->getRepository('userRepository');
        $logger = $this->getRepository('logger');
        $loggingConfig = $this->getRepository('loggingConfig');
        assert($loggingConfig instanceof LogConfigPort);

        if (!$repository instanceof UserRepositoryInterface || !$logger instanceof LoggerInterface) {
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

    /**
     * registerEvents.
     *
     * @throws \RuntimeException
     */
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
     * Register the User module's HTTP routes — both the admin web UI
     * mounted at /admin/users and the JSON API mounted at /api/v1/users.
     *
     * Moved out of app/Config/Routes.php by Phase 3 Group C so adding a
     * new user-management surface no longer requires editing routes.
     */
    public function registerRoutes(RouteCollection $routes): void
    {
        // Admin web UI.
        // SECURITY: admin/users is sensitive — gate the entire group behind
        // the session auth filter (web_auth) AND the role filter (role:admin)
        // so a logged-in non-admin can't even reach the controller.
        $routes->group('admin/users', ['namespace' => 'App\Controllers\Domain\User', 'filter' => 'role:admin'], static function ($routes): void {
            $routes->get('', 'UserController::index');                              // List all users
            $routes->get('create', 'UserController::create');                       // Show create form
            $routes->post('', 'UserController::store');                             // Create user
            $routes->get('(:num)', 'UserController::show/$1');                      // Show single user
            $routes->get('(:num)/edit', 'UserController::edit/$1');                 // Show edit form
            $routes->post('(:num)', 'UserController::update/$1');                   // Update user
            $routes->post('(:num)/delete', 'UserController::delete/$1');            // Delete user (soft)
            $routes->get('(:num)/reset-password', 'UserController::resetPassword/$1');     // Show password reset form
            $routes->post('(:num)/reset-password', 'UserController::storePassword/$1');    // Reset password
        });

        // JSON API (admin only). Mutating endpoints opt into idempotency so
        // retried POST/PUT/DELETE with the same Idempotency-Key replay the
        // original response instead of duplicating side-effects.
        $routes->group(
            'api/v1/users',
            ['namespace' => 'App\Controllers\Api', 'filter' => ['jwt', 'role:admin', 'idempotency']],
            static function ($routes): void {
                $routes->get('', 'UserController::index');                              // GET /api/v1/users
                $routes->post('', 'UserController::create');                            // POST /api/v1/users
                $routes->get('(:num)', 'UserController::show/$1');                      // GET /api/v1/users/1
                $routes->put('(:num)', 'UserController::update/$1');                    // PUT /api/v1/users/1
                $routes->delete('(:num)', 'UserController::delete/$1');                 // DELETE /api/v1/users/1
                $routes->post('(:num)/reset-password', 'UserController::resetPassword/$1');  // POST /api/v1/users/1/reset-password
            }
        );
    }

    /**
     * @return array<mixed>
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
            'sessionManagementService',
        ];
    }

    /**
     * @param array<string, object> $repositories
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    /**
     * getRepository.
     */
    private function getRepository(string $name): object
    {
        return $this->repositories[$name];
    }
}

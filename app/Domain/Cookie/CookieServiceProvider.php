<?php

declare(strict_types=1);

namespace App\Domain\Cookie;

use App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand;
use App\Domain\Cookie\Commands\CreateCookie\CreateCookieHandler;
use App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieCommand;
use App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieHandler;
use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieCommand;
use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieHandler;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieHandler;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEventHandler;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEventHandler;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEventHandler;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEventHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEventHandler;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesHandler;
use App\Domain\Cookie\Queries\GetAllCookies\GetAllCookiesQuery;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdHandler;
use App\Domain\Cookie\Queries\GetCookieById\GetCookieByIdQuery;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedHandler;
use App\Domain\Cookie\Queries\GetCookiesPaginated\GetCookiesPaginatedQuery;
use App\Infrastructure\Attributes\DomainServiceProvider;
use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\ServiceProvider\DomainServiceProviderInterface;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * Cookie Domain Service Provider.
 *
 * This class registers all Cookie domain handlers with the application buses.
 * It's automatically discovered and loaded via the #[DomainServiceProvider] attribute.
 *
 * Responsibilities:
 * - Register all command handlers (Create, Update, Delete)
 * - Register all query handlers (GetById, GetAll, GetPaginated)
 * - Register all event handlers (logging, notifications, etc.)
 * - Declare repository dependencies
 *
 * Benefits of this approach:
 * - Self-contained domain: All registration logic in one place
 * - Zero-configuration: Just add this file and it's auto-discovered
 * - Type-safe: Interface enforcement ensures all methods implemented
 * - Easy to test: Can mock repositories via setRepositories()
 *
 * Adding a new command/query/event:
 * 1. Create the command/query/event class and handler
 * 2. Add one line to the appropriate register method below
 * 3. Done! No need to edit Services.php or any other files
 *
 * @package App\Domain\Cookie
 */
#[DomainServiceProvider]
final class CookieServiceProvider implements DomainServiceProviderInterface
{
    /**
     * Injected repositories.
     *
     * @var array<string, object>
     */
    private array $repositories = [];

    /**
     * Register all command handlers for the Cookie domain.
     *
     * Commands represent write operations that change state.
     *
     * @param CommandBus $commandBus The command bus
     * @return void
     * @throws \RuntimeException
     */
    public function registerCommands(CommandBus $commandBus): void
    {
        $repository = $this->getRepository('cookieRepository');
        $eventDispatcher = $this->getRepository('eventDispatcher');
        $logger = $this->getRepository('logger');

        if (
            !$repository instanceof CookieRepositoryInterface
            || !$eventDispatcher instanceof EventDispatcher
            || !$logger instanceof LoggerInterface
        ) {
            throw new \RuntimeException('Invalid repository, event dispatcher or logger type injected');
        }

        // Register CreateCookie command
        $commandBus->register(
            CreateCookieCommand::class,
            new CreateCookieHandler($repository, $eventDispatcher, $logger)
        );

        // Register UpdateCookie command
        $commandBus->register(
            UpdateCookieCommand::class,
            new UpdateCookieHandler($repository, $eventDispatcher, $logger)
        );

        // Register DeleteCookie command
        $commandBus->register(
            DeleteCookieCommand::class,
            new DeleteCookieHandler($repository, $eventDispatcher, $logger)
        );

        // Register RestoreCookie command
        $commandBus->register(
            RestoreCookieCommand::class,
            new RestoreCookieHandler($repository, $eventDispatcher, $logger)
        );
    }

    /**
     * Register all query handlers for the Cookie domain.
     *
     * Queries represent read operations that return data.
     *
     * @param QueryBus $queryBus The query bus
     * @return void
     * @throws \RuntimeException
     */
    public function registerQueries(QueryBus $queryBus): void
    {
        // Read side depends on a separate query repository (returning DTOs)
        // so query handlers never reach into the write-side aggregate.
        // Post Phase 2 of the stabilization refactor, the query repository
        // queries the same `cookies` table as the write side, but the CQRS
        // code-level separation (distinct class, distinct port) is preserved.
        $repository = $this->getRepository('cookieQueryRepository');
        $logger = $this->getRepository('logger');
        $loggingConfig = $this->getRepository('loggingConfig');

        if (
            !$repository instanceof CookieQueryRepositoryInterface
            || !$logger instanceof LoggerInterface
            || !$loggingConfig instanceof Logging
        ) {
            throw new \RuntimeException('Invalid repository, logger or logging config type injected');
        }

        // Register GetCookieById query
        $queryBus->register(
            GetCookieByIdQuery::class,
            new GetCookieByIdHandler($repository, $logger, $loggingConfig)
        );

        // Register GetAllCookies query
        $queryBus->register(
            GetAllCookiesQuery::class,
            new GetAllCookiesHandler($repository, $logger, $loggingConfig)
        );

        // Register GetCookiesPaginated query
        $queryBus->register(
            GetCookiesPaginatedQuery::class,
            new GetCookiesPaginatedHandler($repository, $logger, $loggingConfig)
        );
    }

    /**
     * Register all event handlers for the Cookie domain.
     *
     * Events represent things that have happened in the domain.
     * Handlers perform side effects (logging, notifications, etc.)
     *
     * @param EventDispatcher $dispatcher The event dispatcher
     * @return void
     */
    public function registerEvents(EventDispatcher $dispatcher): void
    {
        $logger = LoggerFactory::create('cookie.events');

        // Register CookieCreated event handler
        $dispatcher->subscribe(
            CookieCreatedEvent::class,
            new CookieCreatedEventHandler($logger)
        );

        // Register CookieUpdated event handler
        $dispatcher->subscribe(
            CookieUpdatedEvent::class,
            new CookieUpdatedEventHandler($logger)
        );

        // Register CookieDeleted event handler
        $dispatcher->subscribe(
            CookieDeletedEvent::class,
            new CookieDeletedEventHandler($logger)
        );

        // Register CookieStockChanged event handler (C4 — raised by the
        // entity through the AggregateRoot event bag).
        $dispatcher->subscribe(
            CookieStockChangedEvent::class,
            new CookieStockChangedEventHandler($logger)
        );

        // Register CookieRestored event handler. The original implementation
        // raised the event but never registered a subscriber, so the row's
        // "restored" lifecycle transition silently dropped on the floor —
        // audit lines and any future read-model projection wouldn't fire.
        $dispatcher->subscribe(
            CookieRestoredEvent::class,
            new CookieRestoredEventHandler($logger)
        );
    }

    /**
     * Get the list of repository service names needed by this provider.
     *
     * These are resolved by ServiceProviderRegistry::registerAll():
     * - 'cookieRepository' / 'cookieQueryRepository': auto-discovered from
     *   classes tagged with #[AutoBind] (Phase 3 Group B).
     * - 'eventDispatcher' / 'logger' / 'loggingConfig': supplied by
     *   Services::ensureProvidersRegistered().
     *
     * @return array<mixed>
     */
    public function getRepositories(): array
    {
        return [
            'cookieRepository',
            'cookieQueryRepository',
            'eventDispatcher',
            'logger',
            'loggingConfig',
        ];
    }

    /**
     * Set repositories needed by this provider.
     *
     * Called by ServiceProviderRegistry to inject dependencies.
     *
     * @param array<string, object> $repositories Map of repository name => instance
     * @return void
     */
    public function setRepositories(array $repositories): void
    {
        $this->repositories = $repositories;
    }

    /**
     * Get a repository by name.
     *
     * Returns the requested repository or dispatcher instance.
     * Caller must verify the type with instanceof before use.
     *
     * @param string $name Repository name
     * @return object The requested repository or dispatcher
     */
    private function getRepository(string $name): object
    {
        return $this->repositories[$name];
    }
}

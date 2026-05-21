<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProvider;

use App\Infrastructure\Bus\CommandBus;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Bus\QueryBus;
use CodeIgniter\Router\RouteCollection;

/**
 * Interface for Domain Service Providers.
 *
 * Each domain should implement this interface to register its
 * commands, queries, and event handlers with the application buses.
 *
 * Purpose:
 * - Encapsulates all domain registration logic in one place
 * - Keeps domain self-contained and modular
 * - Enables automatic discovery and registration
 * - Eliminates need to edit Services.php when adding domains
 *
 * Implementation Example:
 * ```php
 * #[DomainServiceProvider]
 * class CookieServiceProvider implements DomainServiceProviderInterface
 * {
 *     public function registerCommands(CommandBus $bus): void
 *     {
 *         $bus->register(
 *             CreateCookieCommand::class,
 *             new CreateCookieHandler($this->getRepository('cookieRepository'))
 *         );
 *     }
 *
 *     public function registerQueries(QueryBus $bus): void
 *     {
 *         $bus->register(
 *             GetCookieByIdQuery::class,
 *             new GetCookieByIdHandler($this->getRepository('cookieRepository'))
 *         );
 *     }
 *
 *     public function registerEvents(EventDispatcher $dispatcher): void
 *     {
 *         $dispatcher->subscribe(CookieCreatedEvent::class, new LogCookieCreated());
 *     }
 *
 *     public function getRepositories(): array
 *     {
 *         return ['cookieRepository'];
 *     }
 * }
 * ```
 *
 * @package App\Infrastructure\ServiceProvider
 */
interface DomainServiceProviderInterface
{
    /**
     * Register all command handlers for this domain.
     *
     * @param CommandBus $commandBus The command bus instance
     * @return void
     */
    public function registerCommands(CommandBus $commandBus): void;

    /**
     * Register all query handlers for this domain.
     *
     * @param QueryBus $queryBus The query bus instance
     * @return void
     */
    public function registerQueries(QueryBus $queryBus): void;

    /**
     * Register all event listeners/handlers for this domain.
     *
     * @param EventDispatcher $dispatcher The event dispatcher instance
     * @return void
     */
    public function registerEvents(EventDispatcher $dispatcher): void;

    /**
     * Get the list of repository service names needed by this domain.
     *
     * These names correspond to methods in Services.php (e.g., 'cookieRepository').
     * The ServiceProviderRegistry will resolve these and inject them.
     *
     * @return array<mixed> Array of repository service names
     */
    public function getRepositories(): array;

    /**
     * Set repositories needed by this provider.
     *
     * Called by ServiceProviderRegistry to inject dependencies.
     *
     * @param array<string, object> $repositories Map of repository name => instance
     * @return void
     */
    public function setRepositories(array $repositories): void;

    /**
     * Register the HTTP routes owned by this domain.
     *
     * Called from app/Config/Routes.php after the framework-shell routes
     * are declared. Implementations should attach domain-scoped groups
     * (e.g. /cookies/*) to the supplied collection.
     *
     * Providers without HTTP routes can `use RegisterRoutesNoop;` to inherit
     * a default no-op implementation.
     *
     * @param RouteCollection $routes The application's route collection.
     * @return void
     */
    public function registerRoutes(RouteCollection $routes): void;
}

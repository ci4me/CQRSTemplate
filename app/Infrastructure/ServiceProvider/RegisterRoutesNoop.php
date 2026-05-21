<?php

declare(strict_types=1);

namespace App\Infrastructure\ServiceProvider;

use CodeIgniter\Router\RouteCollection;

/**
 * Default no-op implementation of {@see DomainServiceProviderInterface::registerRoutes}.
 *
 * Domain providers that don't expose HTTP routes can `use RegisterRoutesNoop;`
 * to satisfy the interface contract without writing an empty method body
 * every time.
 *
 * Providers that DO expose routes implement registerRoutes() themselves
 * and omit this trait — the override takes precedence and the trait is
 * not used.
 *
 * @package App\Infrastructure\ServiceProvider
 */
trait RegisterRoutesNoop
{
    /**
     * Default registerRoutes() — does nothing.
     *
     * @param RouteCollection $routes
     * @return void
     */
    public function registerRoutes(RouteCollection $routes): void
    {
        // intentionally empty - providers without routes inherit this no-op
        unset($routes);
    }
}

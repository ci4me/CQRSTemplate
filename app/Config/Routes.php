<?php

use App\Infrastructure\ServiceProvider\ServiceProviderRegistry;
use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */

/*
 * --------------------------------------------------------------------
 * Framework-shell routes
 * --------------------------------------------------------------------
 * Anything that's NOT owned by a specific domain stays here: the root
 * page, the health check, the dashboard landing, and any future
 * framework-default routes. Domain-scoped route groups (cookies/*,
 * auth/*, admin/users/*, api/v1/*) used to live in this file too —
 * Phase 3 Group C moved them into per-domain ServiceProviders, mounted
 * by the auto-discovery loop below. Adding a new domain no longer
 * requires editing this file.
 */
$routes->get('/', 'Home::index');
$routes->get('dashboard', 'Home::dashboard');
$routes->get('health', 'HealthController::index');

/*
 * --------------------------------------------------------------------
 * Auto-mount domain routes
 * --------------------------------------------------------------------
 * Every #[DomainServiceProvider]-tagged class participates here. The
 * registry caches the discovered providers, so this loop is cheap on
 * subsequent calls within the same request.
 */
foreach (ServiceProviderRegistry::discovered() as $provider) {
    $provider->registerRoutes($routes);
}

<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');
$routes->get('dashboard', 'Home::dashboard');
$routes->get('health', 'HealthController::index');

/*
 * --------------------------------------------------------------------
 * Cookie Domain Routes - CQRS Pattern
 * --------------------------------------------------------------------
 */
$routes->group('cookies', ['namespace' => 'App\Controllers\Domain\Cookie'], static function ($routes) {
    $routes->get('', 'CookieController::index');                    // List all cookies
    $routes->get('create', 'CookieController::create');             // Show create form
    $routes->post('', 'CookieController::store');                   // Store new cookie
    $routes->get('(:num)', 'CookieController::show/$1');            // Show single cookie
    $routes->get('(:num)/edit', 'CookieController::edit/$1');       // Show edit form
    $routes->post('(:num)', 'CookieController::update/$1');         // Update cookie
    $routes->post('(:num)/delete', 'CookieController::delete/$1');  // Delete cookie (soft)
});

/*
 * --------------------------------------------------------------------
 * Authentication Routes - Web (Traditional Forms)
 * --------------------------------------------------------------------
 * SECURITY:
 * - Rate limiting on login/register to prevent brute force attacks
 * - 5 attempts per 5 minutes per IP address
 */
$routes->group('auth', ['namespace' => 'App\Controllers\Domain\Auth'], static function ($routes) {
    $routes->get('register', 'AuthController::showRegister');
    $routes->post('register', 'AuthController::register', ['filter' => 'ratelimit:5,300']);
    $routes->get('login', 'AuthController::showLogin');
    $routes->post('login', 'AuthController::login', ['filter' => 'ratelimit:5,300']);
    $routes->post('logout', 'AuthController::logout');
});

/*
 * --------------------------------------------------------------------
 * User Management Routes - Web (Protected)
 * --------------------------------------------------------------------
 */
$routes->group('admin/users', ['namespace' => 'App\Controllers\Domain\User'], static function ($routes) {
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

/*
 * --------------------------------------------------------------------
 * API Routes - RESTful Authentication
 * --------------------------------------------------------------------
 * SECURITY:
 * - Rate limiting on auth endpoints (5/5min to prevent brute force)
 * - JWT authentication required for protected endpoints
 * - Admin role required for user management operations
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    // Public endpoints (with rate limiting)
    $routes->post('auth/register', 'AuthController::register', ['filter' => 'ratelimit:5,300']);
    $routes->post('auth/login', 'AuthController::login', ['filter' => 'ratelimit:5,300']);
    $routes->post('auth/refresh', 'AuthController::refresh', ['filter' => 'ratelimit:10,300']);
    $routes->post('auth/password/request-reset', 'AuthController::requestPasswordReset', ['filter' => 'ratelimit:3,300']);
    $routes->post('auth/password/reset', 'AuthController::resetPassword', ['filter' => 'ratelimit:5,300']);

    // Protected endpoints (require JWT)
    $routes->group('auth', ['filter' => 'jwt'], static function ($routes) {
        $routes->post('logout', 'AuthController::logout');
        $routes->get('me', 'AuthController::me');

        // Session management
        $routes->get('sessions', 'AuthController::listSessions');
        $routes->delete('sessions/all', 'AuthController::revokeAllSessions');
        $routes->delete('sessions/(:num)', 'AuthController::revokeSession/$1');
    });

    // User management API (admin only)
    $routes->group('users', ['filter' => ['jwt', 'role:admin']], static function ($routes) {
        $routes->get('', 'UserController::index');                              // GET /api/v1/users (admin only)
        $routes->post('', 'UserController::create');                            // POST /api/v1/users (admin only)
        $routes->get('(:num)', 'UserController::show/$1');                      // GET /api/v1/users/1 (admin only)
        $routes->put('(:num)', 'UserController::update/$1');                    // PUT /api/v1/users/1 (admin only)
        $routes->delete('(:num)', 'UserController::delete/$1');                 // DELETE /api/v1/users/1 (admin only)
        $routes->post('(:num)/reset-password', 'UserController::resetPassword/$1');  // POST /api/v1/users/1/reset-password (admin only)
    });
});

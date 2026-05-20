<?php

declare(strict_types=1);

/**
 * View-layer permission gating helpers (E3).
 *
 * Views call `can('cookies.update')` to gate Edit/Delete buttons. The
 * backend still enforces the permission via PermissionMiddleware on the
 * route — these helpers stop us from rendering buttons the user can't
 * actually click, AND they're the foundation for showing or hiding menu
 * items, dashboard widgets, etc.
 *
 * Both helpers swallow exceptions and return false on failure so a
 * misconfigured permission name never blanks the page; the backend remains
 * the source of truth for actual authorisation.
 *
 * Load via Config\Autoload or `helper('auth_ui')` from a controller.
 */

use App\Domain\Shared\ValueObjects\Permission;
use App\Infrastructure\Auth\Services\ActorResolver;
use Config\Services;

if (!function_exists('current_actor')) {
    /**
     * Resolve the actor for the current request. Returns Actor::system()
     * when no user is bound (e.g. in CLI or in tests that omit auth).
     *
     * @return \App\Domain\Shared\ValueObjects\Actor
     */
    function current_actor(): \App\Domain\Shared\ValueObjects\Actor
    {
        $resolver = new ActorResolver();
        $request = Services::request();
        return $resolver->resolve($request instanceof \CodeIgniter\HTTP\RequestInterface ? $request : null);
    }
}

if (!function_exists('can')) {
    /**
     * `<?php if (can('cookies.update')): ?> ... <?php endif ?>`
     *
     * @param string $permissionName Dotted permission identifier
     * @return bool
     */
    function can(string $permissionName): bool
    {
        try {
            $permission = Permission::fromString($permissionName);
        } catch (\InvalidArgumentException) {
            return false;
        }

        try {
            return Services::permissionService()->allows(current_actor(), $permission);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (!function_exists('cannot')) {
    /**
     * Inverse of {@see can()} — convenient for "show only when forbidden"
     * messaging.
     *
     * @param string $permissionName
     * @return bool
     */
    function cannot(string $permissionName): bool
    {
        return !can($permissionName);
    }
}

if (!function_exists('any_of')) {
    /**
     * True when the actor holds at least one of the supplied permissions.
     *
     * `<?php if (any_of('cookies.update', 'cookies.delete')): ?>` ...
     *
     * @param string ...$permissionNames
     * @return bool
     */
    function any_of(string ...$permissionNames): bool
    {
        foreach ($permissionNames as $name) {
            if (can($name)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('all_of')) {
    /**
     * True only when the actor holds every supplied permission.
     *
     * @param string ...$permissionNames
     * @return bool
     */
    function all_of(string ...$permissionNames): bool
    {
        foreach ($permissionNames as $name) {
            if (!can($name)) {
                return false;
            }
        }
        return true;
    }
}

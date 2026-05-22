<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Middleware;

use App\Infrastructure\Auth\Middleware\RoleAuthorizationMiddleware;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Psr\Log\NullLogger;

/**
 * Drives all branches of the RBAC filter without crossing the HTTP boundary:
 * anonymous (401), authorised, forbidden, malformed role objects (admin
 * fail-secure), admin bypass.
 */
final class RoleAuthorizationMiddlewareTest extends CIUnitTestCase
{
    public function test_returns_401_when_no_authenticated_user_attached(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();

        $result = $mw->before($request, ['admin']);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(401, $result->getStatusCode());
    }

    public function test_returns_null_when_admin_user_meets_admin_requirement(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $this->attachUser($request, role: 'admin');

        $result = $mw->before($request, ['admin']);

        $this->assertNull($result, 'admin must be allowed through admin-gated routes');
    }

    public function test_admin_role_bypasses_other_role_checks(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $this->attachUser($request, role: 'admin');

        // Admin should pass even when the filter requires 'customer'.
        $this->assertNull($mw->before($request, ['customer']));
    }

    public function test_returns_403_when_customer_attempts_admin_route(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $this->attachUser($request, role: 'customer');

        $result = $mw->before($request, ['admin']);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_returns_403_when_user_role_cannot_be_resolved(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        // Attach a user without getRole() and without a `role` property — the
        // resolver must throw RuntimeException and the middleware must respond
        // with 403 (fail-secure: no silent downgrade).
        /** @phpstan-ignore-next-line dynamic property */
        $request->user = new \stdClass();

        $result = $mw->before($request, ['admin']);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(403, $result->getStatusCode());
    }

    public function test_defaults_to_admin_role_when_no_argument_supplied(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $this->attachUser($request, role: 'customer');

        $result = $mw->before($request, null);
        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame(403, $result->getStatusCode(), 'customer must not satisfy default admin');
    }

    public function test_after_filter_returns_null(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());

        $result = $mw->after($this->makeRequest(), new Response(new App()));

        $this->assertNull($result);
    }

    public function test_resolves_role_from_role_property_when_no_get_role_method(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $user = new \stdClass();
        $user->role = 'admin';
        /** @phpstan-ignore-next-line dynamic property */
        $request->user = $user;

        $this->assertNull($mw->before($request, ['admin']));
    }

    public function test_resolves_role_from_backed_enum(): void
    {
        $mw = new RoleAuthorizationMiddleware(new NullLogger());
        $request = $this->makeRequest();
        $this->attachUser($request, role: RoleTestEnum::Admin);

        $this->assertNull($mw->before($request, ['admin']));
    }

    private function makeRequest(): IncomingRequest
    {
        $config = new App();
        $uri = new SiteURI($config);
        $uri->setPath('/api/v1/users');
        return new IncomingRequest($config, $uri, '', new UserAgent());
    }

    private function attachUser(IncomingRequest $request, string|\UnitEnum $role): void
    {
        $user = new RoleTestUser($role);
        /** @phpstan-ignore-next-line dynamic property */
        $request->user = $user;
    }
}

/**
 * Test fixture: a user-shaped object with a getRole() method and an id.
 */
final class RoleTestUser
{
    public int $id = 1;

    public function __construct(private readonly string|\UnitEnum $role)
    {
    }

    public function getRole(): string|\UnitEnum
    {
        return $this->role;
    }

    public function getId(): int
    {
        return $this->id;
    }
}

enum RoleTestEnum: string
{
    case Admin = 'admin';
    case Customer = 'customer';
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use App\Domain\Shared\ValueObjects\Permission;
use App\Infrastructure\Auth\Services\ActorResolver;
use App\Infrastructure\Auth\Services\PermissionService;
use App\Infrastructure\Http\ApiResponse;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Route-level permission check (D3).
 *
 * Usage in Config/Routes.php:
 *   ['filter' => 'permission:cookies.update']
 *
 * Resolves the current Actor (via ActorResolver), looks up the permission
 * (via PermissionService), and either lets the request through, returns
 * 401 if unauthenticated (Actor::system from an HTTP request means we
 * could not identify the user), or 403 if the actor lacks the grant.
 *
 * This middleware is purposefully simple: it does NOT cache, and it does
 * NOT short-circuit on internal IPs. Optimisations belong in
 * PermissionService where they can serve all callers.
 */
final class PermissionMiddleware implements FilterInterface
{
    /** @var ActorResolver */
    private ActorResolver $actorResolver;
    /** @var PermissionService */
    private PermissionService $permissions;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param ActorResolver|null     $actorResolver
     * @param PermissionService|null $permissions
     * @param LoggerInterface|null   $logger
     */
    public function __construct(
        ?ActorResolver $actorResolver = null,
        ?PermissionService $permissions = null,
        ?LoggerInterface $logger = null
    ) {
        $this->actorResolver = $actorResolver ?? \Config\Services::actorResolver();
        $this->permissions = $permissions ?? \Config\Services::permissionService();
        $this->logger = $logger ?? \Config\Services::logger();
    }

    /**
     * before.
     *
     * @param RequestInterface $request
     * @param mixed            $arguments
     * @return RequestInterface|ResponseInterface
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface
    {
        $required = $this->parseArgument($arguments);
        if ($required === null) {
            return $this->forbidden('permission filter requires a permission name argument');
        }

        $actor = $this->actorResolver->resolve($request);
        if ($actor->isSystem()) {
            // No identifiable user on an HTTP request — that is an
            // authentication problem, not a permission problem.
            return ApiResponse::problem(401, 'Unauthorized', 'Authentication required.');
        }

        if (!$this->permissions->allows($actor, $required)) {
            $this->logger->warning('Permission denied', [
                'domain' => 'Auth',
                'middleware' => 'PermissionMiddleware',
                'actor_id' => $actor->id,
                'required' => $required->name,
                'path' => $request->getUri()->getPath(),
            ]);

            return $this->forbidden(sprintf(
                'Actor lacks permission "%s".',
                $required->name
            ));
        }

        return $request;
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed             $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        return $response;
    }

    /**
     * parseArgument.
     *
     * @param mixed $arguments
     * @return Permission|null
     */
    private function parseArgument(mixed $arguments): ?Permission
    {
        if (is_array($arguments) && count($arguments) > 0) {
            $first = $arguments[0];
            if (is_string($first)) {
                try {
                    return Permission::fromString($first);
                } catch (\InvalidArgumentException) {
                    return null;
                }
            }
        }

        if (is_string($arguments)) {
            try {
                return Permission::fromString($arguments);
            } catch (\InvalidArgumentException) {
                return null;
            }
        }

        return null;
    }

    /**
     * forbidden.
     *
     * @param string $detail
     * @return ResponseInterface
     */
    private function forbidden(string $detail): ResponseInterface
    {
        return ApiResponse::problem(403, 'Forbidden', $detail);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Role Authorization Middleware.
 *
 * Enforces role-based access control (RBAC) on protected endpoints.
 * Verifies authenticated user has required role to access resource.
 *
 * Usage in Routes.php:
 * - ['filter' => 'role:admin'] for admin-only access
 * - ['filter' => 'role:customer'] for customer access
 * - ['filter' => 'jwt|role:admin'] for authenticated admin users
 *
 * SECURITY:
 * - Returns 403 Forbidden if user lacks required role
 * - Returns 401 Unauthorized if user not authenticated
 * - Logs authorization failures for security monitoring
 * - Supports multiple roles (admin, customer, etc.)
 */
final readonly class RoleAuthorizationMiddleware implements FilterInterface
{
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param LoggerInterface|null $logger
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? \Config\Services::logger();
    }

    /**
     * Process request before controller execution.
     *
     * @param RequestInterface $request   Current request
     * @param mixed            $arguments Filter arguments [requiredRole]
     * @return RequestInterface|ResponseInterface|null
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface|null
    {
        // Parse required role from arguments
        $requiredRole = $this->parseArguments($arguments);

        // Get authenticated user from request
        $user = $this->getAuthenticatedUser($request);

        // Check if user is authenticated
        if ($user === null) {
            return $this->createUnauthorizedResponse('User not authenticated');
        }

        // Check if user has required role
        try {
            $userRole = $this->getUserRole($user);
        } catch (\RuntimeException $e) {
            $this->logger->error('Role resolution failed; refusing request', [
                'domain' => 'Auth',
                'middleware' => 'RoleAuthorizationMiddleware',
                'exception' => $e->getMessage(),
                'security' => 'CRITICAL',
            ]);

            return $this->createForbiddenResponse();
        }

        if (!$this->hasRequiredRole($userRole, $requiredRole)) {
            $this->logAuthorizationFailure($request, $user, $userRole, $requiredRole);
            return $this->createForbiddenResponse();
        }

        // User authorized - allow request
        return null;
    }

    /**
     * Process response after controller execution.
     *
     * No action needed - authorization is applied before execution.
     *
     * @param RequestInterface  $request   Current request
     * @param ResponseInterface $response  Current response
     * @param mixed             $arguments Filter arguments
     * @return ResponseInterface|null
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface|null
    {
        return null;
    }

    /**
     * Parse filter arguments into required role.
     *
     * @param array<string>|null $arguments Filter arguments [requiredRole]
     * @return string Required role (defaults to 'admin')
     */
    private function parseArguments(?array $arguments): string
    {
        if ($arguments === null || count($arguments) === 0) {
            return 'admin'; // Default to admin role
        }

        // Return first argument as required role
        return $arguments[0];
    }

    /**
     * Get authenticated user from request.
     *
     * User should be set by JWT middleware in request.
     * The user object is stored dynamically as a property on the request.
     *
     * @param RequestInterface $request Current request
     * @return object|null User object or null if not authenticated
     */
    private function getAuthenticatedUser(RequestInterface $request): ?object
    {
        // User is stored in request by JWT middleware as dynamic property
        // PHPStan doesn't know about dynamic properties, so we suppress the error
        /** @phpstan-ignore-next-line property.notFound */
        $user = $request->user ?? null;

        if ($user !== null && is_object($user)) {
            return $user;
        }

        return null;
    }

    /**
     * Get user role from user object.
     *
     * @param object $user User object
     * @return string User role
     * @throws \RuntimeException
     */
    private function getUserRole(object $user): string
    {
        // Assume user object has getRole() method
        if (method_exists($user, 'getRole')) {
            $role = $user->getRole();

            if ($role instanceof \BackedEnum && is_string($role->value)) {
                return $role->value;
            }

            if ($role instanceof \UnitEnum) {
                return $role->name;
            }

            if (is_object($role) && method_exists($role, '__toString')) {
                return (string) $role;
            }

            if (is_string($role)) {
                return $role;
            }
        }

        // Fallback: check for role property
        if (property_exists($user, 'role')) {
            /** @var mixed $role */
            $role = $user->role;

            if (is_string($role)) {
                return $role;
            }
        }

        // SECURITY (A8): fail-secure — if the role cannot be resolved we MUST
        // NOT silently down-grade to a customer; refuse the request instead.
        throw new \RuntimeException(
            'Could not resolve user role for authorization. Refusing request.'
        );
    }

    /**
     * Check if user has required role.
     *
     * Admin role has access to all resources.
     * Other roles must match exactly.
     *
     * @param string $userRole     User's role
     * @param string $requiredRole Required role
     * @return bool True if user has required role
     */
    private function hasRequiredRole(string $userRole, string $requiredRole): bool
    {
        // Admin has access to everything
        if ($userRole === 'admin') {
            return true;
        }

        // Exact role match required
        return $userRole === $requiredRole;
    }

    /**
     * Log authorization failure.
     *
     * @param RequestInterface $request      Current request
     * @param object           $user         User object
     * @param string           $userRole     User's role
     * @param string           $requiredRole Required role
     * @return void
     */
    private function logAuthorizationFailure(RequestInterface $request, object $user, string $userRole, string $requiredRole): void
    {
        assert($request instanceof IncomingRequest);

        // Extract user ID for logging
        $userId = null;
        if (method_exists($user, 'getId')) {
            $userId = $user->getId();
        } elseif (property_exists($user, 'id')) {
            $userId = $user->id;
        }

        $this->logger->warning('Authorization failed - insufficient role', [
            'domain' => 'Security',
            'middleware' => 'RoleAuthorizationMiddleware',
            'user_id' => $userId,
            'user_role' => $userRole,
            'required_role' => $requiredRole,
            'endpoint' => $request->getMethod() . ' ' . $request->getPath(),
            'ip_address' => $request->getIPAddress(),
            'user_agent' => $request->getUserAgent()->getAgentString(),
        ]);
    }

    /**
     * Create 401 Unauthorized response.
     *
     * @param string $message Error message
     * @return ResponseInterface
     */
    private function createUnauthorizedResponse(string $message): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setJSON([
            'error' => 'Unauthorized',
            'message' => $message,
        ]);

        return $response;
    }

    /**
     * Create 403 Forbidden response.
     *
     * @return ResponseInterface
     */
    private function createForbiddenResponse(): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(403);
        $response->setJSON([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions to access this resource',
        ]);

        return $response;
    }
}

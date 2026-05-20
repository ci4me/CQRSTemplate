<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;

/**
 * Session-based role authorization middleware for web routes.
 *
 * Checks that the authenticated user's session role matches
 * the required role. Returns 403 if the user lacks the
 * required permissions.
 *
 * @package App\Infrastructure\Auth\Middleware
 */
final class SessionRoleMiddleware implements FilterInterface
{
    /**
     * @param RequestInterface $request
     * @param mixed            $arguments
     * @return RequestInterface|ResponseInterface|null
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface|null
    {
        /** @var Session $session */
        $session = service('session');

        $requiredRole = $arguments[0] ?? 'admin';
        $userRole = $session->get('role');

        if ($userRole === 'admin') {
            return null;
        }

        if ($userRole !== $requiredRole) {
            $session->setFlashdata('error', 'You do not have permission to access this resource.');
            return redirect()->to('/dashboard');
        }

        return null;
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface  $request
     * @param ResponseInterface $response
     * @param mixed             $arguments
     * @return ResponseInterface|null
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface|null
    {
        return null;
    }
}

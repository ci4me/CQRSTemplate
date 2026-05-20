<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use App\Infrastructure\Auth\AuthContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;

/**
 * Session-based authentication middleware for web routes.
 *
 * Validates that the user has an active session before allowing
 * access to protected web routes. Redirects unauthenticated
 * users to the login page.
 *
 * @package App\Infrastructure\Auth\Middleware
 */
final class SessionAuthMiddleware implements FilterInterface
{
    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface|null
    {
        /** @var Session $session */
        $session = service('session');

        if ($session->get('logged_in') !== true) {
            $session->setFlashdata('error', 'Please log in to continue.');
            return redirect()->to('/auth/login');
        }

        AuthContext::setCurrentUserId((int) $session->get('user_id'));

        return null;
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface|null
    {
        return null;
    }
}

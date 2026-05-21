<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use App\Domain\User\Repositories\UserRepository;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use Psr\Log\LoggerInterface;

/**
 * Session Authentication Middleware (web tier).
 *
 * Mirrors {@see JwtAuthenticationMiddleware} for the API tier but uses the
 * web session as the source of truth. Validates the session, loads the user,
 * verifies they are still active, and attaches the user to the request.
 *
 * Failure modes:
 * - missing/expired session -> redirect to /auth/login with a flash message
 * - session references a deleted/inactive user -> destroy session, redirect
 *
 * Usage in Config/Filters.php:
 * 'web_auth' => SessionAuthMiddleware::class
 *
 * Apply via $filters['web_auth'] = ['before' => ['cookies/*', 'admin/*']]
 */
final class SessionAuthMiddleware implements FilterInterface
{
    /** @var UserRepository */
    private UserRepository $userRepository;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param UserRepository|null  $userRepository
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ?UserRepository $userRepository = null,
        ?LoggerInterface $logger = null
    ) {
        $this->userRepository = $userRepository ?? \Config\Services::userRepository();
        $this->logger = $logger ?? \Config\Services::logger();
    }

    /**
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     * @param RequestInterface $request
     * @param mixed            $arguments
     * @return RequestInterface|ResponseInterface
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface
    {
        $session = session();
        assert($session instanceof Session);

        $userId = $session->get('user_id');

        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return $this->redirectToLogin($session, $request, 'unauthenticated');
        }

        $user = $this->userRepository->findById((int) $userId);

        if ($user === null) {
            $session->destroy();
            return $this->redirectToLogin($session, $request, 'user_not_found');
        }

        if (!$user->isActive()) {
            $session->destroy();
            return $this->redirectToLogin($session, $request, 'user_inactive');
        }

        /** @phpstan-ignore-next-line dynamic property assignment for controller use */
        $request->user = $user;

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
     * redirectToLogin.
     *
     * @param Session          $session
     * @param RequestInterface $request
     * @param string           $reason
     * @return ResponseInterface
     */
    private function redirectToLogin(Session $session, RequestInterface $request, string $reason): ResponseInterface
    {
        $this->logger->info('Session auth redirect to login', [
            'domain' => 'Auth',
            'middleware' => 'SessionAuthMiddleware',
            'reason' => $reason,
            'ip' => $request->getIPAddress(),
            'uri' => $request->getUri()->getPath(),
        ]);

        $session->setFlashdata('error', 'Please log in to continue.');

        return redirect()->to('/auth/login');
    }
}

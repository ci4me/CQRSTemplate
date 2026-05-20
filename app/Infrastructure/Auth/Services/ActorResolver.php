<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\Shared\ValueObjects\Actor;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Session\Session;

/**
 * Resolves the {@see Actor} for the current execution context.
 *
 * Lookup order:
 *  1. Authenticated user attached to the request by JWT or session middleware
 *     (`$request->user`).
 *  2. `user_id` value present in the session (web tier without middleware).
 *  3. Falls back to {@see Actor::system()} for CLI / migrations / jobs
 *     AND for unauthenticated HTTP requests (login, register, password-reset).
 *
 * SECURITY: Actor::system() is no longer auto-allowed by PermissionService
 * (see that class for details). Downstream callers that need to gate on
 * "an actual human did this" should check {@see Actor::isSystem()} and
 * refuse the operation rather than rely on the fallback to magically
 * become an admin.
 *
 * Controllers SHOULD call {@see self::resolve($this->request)} when building
 * commands; the actual auth gating belongs in route filters (jwt / web_auth /
 * role) so by the time we get here, an authenticated user IS available
 * for protected routes.
 */
final class ActorResolver
{
    /**
     * resolve.
     *
     * @param RequestInterface|null $request
     * @return Actor
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function resolve(?RequestInterface $request = null): Actor
    {
        $userId = $this->extractUserId($request);

        if ($userId !== null) {
            return Actor::user($userId);
        }

        return Actor::system();
    }

    /**
     * resolveOrSystem.
     *
     * @param string $systemLabel
     * @return Actor
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function resolveOrSystem(string $systemLabel = 'system'): Actor
    {
        return Actor::system($systemLabel);
    }

    /**
     * extractUserId.
     *
     * @param RequestInterface|null $request
     * @return int|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function extractUserId(?RequestInterface $request): ?int
    {
        $fromRequest = $this->extractFromRequest($request);

        if ($fromRequest !== null) {
            return $fromRequest;
        }

        return $this->extractFromSession();
    }

    /**
     * extractFromRequest.
     *
     * @param RequestInterface|null $request
     * @return int|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function extractFromRequest(?RequestInterface $request): ?int
    {
        if ($request === null) {
            return null;
        }

        /** @phpstan-ignore-next-line dynamic property assigned by auth middleware */
        $user = $request->user ?? null;

        if (is_object($user) && method_exists($user, 'getId')) {
            $id = $user->getId();
            return is_int($id) && $id > 0 ? $id : null;
        }

        return null;
    }

    /**
     * extractFromSession.
     *
     * @return int|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function extractFromSession(): ?int
    {
        if (!function_exists('session')) {
            return null;
        }

        $session = session();
        if (!$session instanceof Session) {
            return null;
        }

        $userId = $session->get('user_id');

        if (is_int($userId) && $userId > 0) {
            return $userId;
        }

        if (is_string($userId) && ctype_digit($userId) && (int) $userId > 0) {
            return (int) $userId;
        }

        return null;
    }
}

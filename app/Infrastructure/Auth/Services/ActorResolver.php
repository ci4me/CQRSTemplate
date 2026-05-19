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
 *  3. Falls back to {@see Actor::system()} for CLI / migrations / jobs.
 *
 * Controllers should call {@see self::resolve($this->request)} when building
 * commands; CLI / background contexts can call {@see self::resolveOrSystem()}.
 */
final class ActorResolver
{
    public function resolve(?RequestInterface $request = null): Actor
    {
        $userId = $this->extractUserId($request);

        if ($userId !== null) {
            return Actor::user($userId);
        }

        return Actor::system();
    }

    public function resolveOrSystem(string $systemLabel = 'system'): Actor
    {
        return Actor::system($systemLabel);
    }

    private function extractUserId(?RequestInterface $request): ?int
    {
        $fromRequest = $this->extractFromRequest($request);

        if ($fromRequest !== null) {
            return $fromRequest;
        }

        return $this->extractFromSession();
    }

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

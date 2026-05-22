<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

/**
 * Domain port for session-revocation side effects.
 *
 * The User domain uses this port to invalidate active sessions after
 * security-critical operations (password change, account lockout, role
 * downgrade) without taking a hard dependency on the framework's session
 * store. The Infrastructure adapter knows about the `sessions` table,
 * JWT JTI tracking, and audit logging.
 *
 * Methods are intentionally narrow — only the operations domain handlers
 * actually invoke are exposed here. Anything richer (administrative
 * listing, per-session revocation) stays on the concrete service.
 *
 * @package App\Domain\User\Ports
 */
interface SessionManagerInterface
{
    /**
     * Forcibly revoke every active session for $userId.
     *
     * Typical callers:
     *  - Password-change handlers (CRITICAL: previously-issued JWTs must die)
     *  - Account-lockout handlers
     *  - Administrative force-logout commands
     *
     * Implementations should be idempotent: a second call after every
     * session has already been revoked is a no-op, not an error.
     *
     * @param int $userId The user whose sessions are to be revoked.
     */
    public function revokeAllUserSessions(int $userId): void;
}

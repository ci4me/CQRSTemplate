<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Session Management Service.
 *
 * Manages active user sessions with device tracking and concurrent limits.
 *
 * SECURITY FEATURES:
 * - Device fingerprinting for session identification
 * - Concurrent session limits (default: 5 sessions per user)
 * - Automatic cleanup of expired sessions
 * - Forced logout (revoke all user sessions)
 * - Last activity tracking for idle timeout
 *
 * @package App\Infrastructure\Auth\Services
 */
final class SessionManagementService
{
    private const int DEFAULT_MAX_SESSIONS = 5;
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct()
    {
        $this->logger = LoggerFactory::create('auth.session-management');
    }

    /**
     * Create new session record.
     *
     * @param int         $userId          User ID
     * @param string      $accessTokenJti  Access token JTI
     * @param string      $refreshTokenJti Refresh token JTI
     * @param string      $ipAddress       Client IP address
     * @param string|null $userAgent       Browser/device user agent
     * @param int         $expiresAt       Refresh token expiration timestamp
     * @return int Session ID
     */
    public function createSession(
        int $userId,
        string $accessTokenJti,
        string $refreshTokenJti,
        string $ipAddress,
        ?string $userAgent,
        int $expiresAt
    ): int {
        $db = \Config\Database::connect();

        // Generate device fingerprint
        $deviceFingerprint = $this->generateDeviceFingerprint($ipAddress, $userAgent);

        // Check concurrent session limit
        $this->enforceSessionLimit($userId);

        $db->table('sessions')->insert([
            'user_id' => $userId,
            'access_token_jti' => $accessTokenJti,
            'refresh_token_jti' => $refreshTokenJti,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $deviceFingerprint,
            'last_activity_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'revoked' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $sessionId = (int) $db->insertID();

        $this->logger->info('Session created', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
            'device_fingerprint' => $deviceFingerprint,
        ]);

        return $sessionId;
    }

    /**
     * Update session last activity timestamp.
     *
     * @param string $accessTokenJti Access token JTI
     * @return void
     */
    public function updateLastActivity(string $accessTokenJti): void
    {
        $db = \Config\Database::connect();

        $db->table('sessions')
            ->where('access_token_jti', $accessTokenJti)
            ->where('revoked', false)
            ->update([
                'last_activity_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Revoke specific session.
     *
     * @param int $sessionId Session ID
     * @param int $userId    User ID (for authorization check)
     * @return void
     */
    public function revokeSession(int $sessionId, int $userId): void
    {
        $db = \Config\Database::connect();

        $db->table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->update([
                'revoked' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->logger->info('Session revoked', [
            'domain' => 'Auth',
            'session_id' => $sessionId,
            'user_id' => $userId,
        ]);
    }

    /**
     * Revoke session by access token JTI.
     *
     * SECURITY: Used during logout to ensure complete session revocation (CR-2.1)
     *
     * @param string $accessTokenJti Access token JTI
     * @param int    $userId         User ID (for authorization check)
     * @return void
     */
    public function revokeSessionByAccessJti(string $accessTokenJti, int $userId): void
    {
        $db = \Config\Database::connect();

        $affectedRows = $db->table('sessions')
            ->where('access_token_jti', $accessTokenJti)
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->update([
                'revoked' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if ($affectedRows <= 0) {
            return;
        }

        $this->logger->info('Session revoked by access token JTI', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'access_token_jti' => $accessTokenJti,
        ]);
    }

    /**
     * Revoke all user sessions (forced logout).
     *
     * @param int $userId User ID
     * @return void
     */
    public function revokeAllUserSessions(int $userId): void
    {
        $db = \Config\Database::connect();

        $affectedRows = $db->table('sessions')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->update([
                'revoked' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->logger->warning('All user sessions revoked', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'sessions_revoked' => $affectedRows,
            'security' => 'CRITICAL',
        ]);
    }

    /**
     * Get active sessions for user.
     *
     * @param int $userId User ID
     * @return array<int, array<string, mixed>>
     */
    public function getActiveSessions(int $userId): array
    {
        $db = \Config\Database::connect();

        $result = $db->table('sessions')
            ->select('id, ip_address, user_agent, last_activity_at, created_at')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->orderBy('last_activity_at', 'DESC')
            ->get();

        if ($result === false) {
            return [];
        }

        return $result->getResultArray();
    }

    /**
     * Clean up expired sessions.
     *
     * @return int Number of sessions cleaned
     */
    public function cleanupExpiredSessions(): int
    {
        $db = \Config\Database::connect();

        $builder = $db->table('sessions');
        $builder->where('expires_at <', date('Y-m-d H:i:s'));
        $result = $builder->delete();

        // @phpstan-ignore-next-line (affectedRows can return string in some DB drivers)
        $affectedRows = is_bool($result) ? 0 : (int) $db->affectedRows();

        $this->logger->info('Expired sessions cleaned up', [
            'domain' => 'Auth',
            'sessions_deleted' => $affectedRows,
        ]);

        return $affectedRows;
    }

    /**
     * Generate device fingerprint from IP and user agent.
     *
     * @param string      $ipAddress IP address
     * @param string|null $userAgent User agent string
     * @return string SHA-256 hash
     */
    private function generateDeviceFingerprint(string $ipAddress, ?string $userAgent): string
    {
        $data = $ipAddress . '|' . ($userAgent ?? 'unknown');
        return hash('sha256', $data);
    }

    /**
     * Enforce concurrent session limit.
     *
     * Revokes oldest sessions if limit exceeded.
     *
     * @param int $userId User ID
     * @return void
     */
    private function enforceSessionLimit(int $userId): void
    {
        $db = \Config\Database::connect();

        $sessionCount = $db->table('sessions')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->countAllResults();

        if ($sessionCount < self::DEFAULT_MAX_SESSIONS) {
            return;
        }

        // Revoke oldest session
        $result = $db->table('sessions')
            ->select('id')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->orderBy('last_activity_at', 'ASC')
            ->limit(1)
            ->get();

        if ($result === false) {
            return;
        }

        $oldestSession = $result->getRowArray();

        if ($oldestSession === null) {
            return;
        }

        $this->revokeSession((int) $oldestSession['id'], $userId);

        $this->logger->info('Oldest session revoked due to concurrent limit', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'max_sessions' => self::DEFAULT_MAX_SESSIONS,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Security Event Service.
 *
 * Records security-relevant events for audit trail and analysis.
 *
 * EVENT TYPES:
 * - login_success, login_failure
 * - password_changed, password_reset_requested
 * - mfa_enabled, mfa_disabled
 * - session_created, session_revoked
 * - suspicious_activity, rate_limit_exceeded
 * - token_theft_detected, account_locked
 *
 * @package App\Infrastructure\Auth\Services
 */
final class SecurityEventService
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::create('auth.security-events');
    }

    /**
     * Log security event.
     *
     * @param string $eventType Event type identifier
     * @param string $severity Severity level (low, medium, high, critical)
     * @param int|null $userId User ID if applicable
     * @param string $ipAddress Client IP address
     * @param string $description Human-readable description
     * @param array<string, mixed>|null $metadata Additional context
     */
    public function logEvent(
        string $eventType,
        string $severity,
        ?int $userId,
        string $ipAddress,
        string $description,
        ?array $metadata = null
    ): void {
        $db = \Config\Database::connect();

        $db->table('security_events')->insert([
            'event_type' => $eventType,
            'severity' => $severity,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'description' => $description,
            'metadata' => $metadata !== null ? json_encode($metadata) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->info('Security event logged', [
            'domain' => 'Auth',
            'event_type' => $eventType,
            'severity' => $severity,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
        ]);
    }

    /**
     * Log login success event.
     */
    public function logLoginSuccess(int $userId, string $ipAddress, string $userAgent): void
    {
        $this->logEvent(
            'login_success',
            'low',
            $userId,
            $ipAddress,
            'User logged in successfully',
            ['user_agent' => $userAgent]
        );
    }

    /**
     * Log login failure event.
     */
    public function logLoginFailure(string $email, string $ipAddress, string $reason, string $userAgent): void
    {
        $this->logEvent(
            'login_failure',
            'medium',
            null,
            $ipAddress,
            "Login failed: {$reason}",
            [
                'email' => $email,
                'reason' => $reason,
                'user_agent' => $userAgent,
            ]
        );
    }

    /**
     * Log password change event.
     */
    public function logPasswordChanged(int $userId, string $ipAddress): void
    {
        $this->logEvent(
            'password_changed',
            'high',
            $userId,
            $ipAddress,
            'User password changed',
            ['changed_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Log suspicious activity.
     *
     * @param int|null $userId User ID if known
     * @param string $ipAddress IP address
     * @param string $description Description of suspicious activity
     * @param array<string, mixed> $metadata Additional context data
     */
    public function logSuspiciousActivity(
        ?int $userId,
        string $ipAddress,
        string $description,
        array $metadata
    ): void {
        $this->logEvent(
            'suspicious_activity',
            'critical',
            $userId,
            $ipAddress,
            $description,
            $metadata
        );
    }

    /**
     * Log token theft detection.
     */
    public function logTokenTheft(int $userId, string $ipAddress, string $jti): void
    {
        $this->logEvent(
            'token_theft_detected',
            'critical',
            $userId,
            $ipAddress,
            'Possible token theft detected - revoked refresh token reused',
            ['jti' => $jti]
        );
    }

    /**
     * Get recent security events for user.
     *
     * @param int $userId User ID
     * @param int $limit Number of events to retrieve
     * @return array<int, array<string, mixed>>
     */
    public function getRecentEvents(int $userId, int $limit = 20): array
    {
        $db = \Config\Database::connect();

        $result = $db->table('security_events')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        if ($result === false) {
            return [];
        }

        return $result->getResultArray();
    }
}

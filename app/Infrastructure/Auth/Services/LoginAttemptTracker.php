<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Login Attempt Tracker.
 *
 * Tracks login attempts for brute force detection and account lockout.
 *
 * SECURITY FEATURES:
 * - Records all login attempts (success and failure)
 * - Detects brute force attacks (5+ failures in 5 minutes)
 * - Enables account lockout policies
 * - Identifies credential stuffing patterns
 * - Provides audit trail for investigations
 *
 * @package App\Infrastructure\Auth\Services
 */
final class LoginAttemptTracker
{
    private const int BRUTE_FORCE_THRESHOLD = 5;
    private const int BRUTE_FORCE_WINDOW_SECONDS = 300; // 5 minutes
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::create('auth.login-attempts');
    }

    /**
     * Record login attempt.
     *
     * @param string $email Email used in login attempt
     * @param int|null $userId User ID if account exists
     * @param string $ipAddress Client IP address
     * @param string|null $userAgent Browser/device user agent
     * @param bool $success Whether login succeeded
     * @param string|null $failureReason Reason for failure
     */
    public function recordAttempt(
        string $email,
        ?int $userId,
        string $ipAddress,
        ?string $userAgent,
        bool $success,
        ?string $failureReason = null
    ): void {
        $db = \Config\Database::connect();

        $db->table('login_attempts')->insert([
            'email' => $email,
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'success' => $success,
            'failure_reason' => $failureReason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($success) {
            return;
        }

        $this->logger->warning('Failed login attempt', [
            'domain' => 'Auth',
            'email' => $email,
            'ip_address' => $ipAddress,
            'reason' => $failureReason,
        ]);
    }

    /**
     * Check if IP address is under brute force attack.
     *
     * @param string $ipAddress IP address to check
     * @return bool True if brute force detected
     */
    public function isBruteForceDetected(string $ipAddress): bool
    {
        $db = \Config\Database::connect();

        $windowStart = date('Y-m-d H:i:s', time() - self::BRUTE_FORCE_WINDOW_SECONDS);

        $failureCount = $db->table('login_attempts')
            ->where('ip_address', $ipAddress)
            ->where('success', false)
            ->where('created_at >', $windowStart)
            ->countAllResults();

        if ($failureCount >= self::BRUTE_FORCE_THRESHOLD) {
            $this->logger->critical('Brute force attack detected', [
                'domain' => 'Auth',
                'ip_address' => $ipAddress,
                'failure_count' => $failureCount,
                'window_seconds' => self::BRUTE_FORCE_WINDOW_SECONDS,
                'security' => 'CRITICAL',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get recent login attempts for user.
     *
     * @param int $userId User ID
     * @param int $limit Number of attempts to retrieve
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAttempts(int $userId, int $limit = 10): array
    {
        $db = \Config\Database::connect();

        $result = $db->table('login_attempts')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        if ($result === false) {
            return [];
        }

        return $result->getResultArray();
    }

    /**
     * Get failed login count for email in time window.
     *
     * @param string $email Email address
     * @param int $windowSeconds Time window in seconds
     * @return int Number of failed attempts
     */
    public function getFailedAttemptCount(string $email, int $windowSeconds): int
    {
        $db = \Config\Database::connect();

        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        $count = $db->table('login_attempts')
            ->where('email', $email)
            ->where('success', false)
            ->where('created_at >', $windowStart)
            ->countAllResults();

        return (int) $count;
    }

    /**
     * Clean up old login attempts.
     *
     * @param int $daysToKeep Number of days to retain
     * @return int Number of records deleted
     */
    public function cleanup(int $daysToKeep = 90): int
    {
        $db = \Config\Database::connect();

        $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));

        $builder = $db->table('login_attempts');
        $builder->where('created_at <', $cutoffDate);
        $result = $builder->delete();

        // @phpstan-ignore-next-line (affectedRows can return string in some DB drivers)
        $affectedRows = is_bool($result) ? 0 : (int) $db->affectedRows();

        $this->logger->info('Old login attempts cleaned up', [
            'domain' => 'Auth',
            'records_deleted' => $affectedRows,
            'days_kept' => $daysToKeep,
        ]);

        return $affectedRows;
    }
}

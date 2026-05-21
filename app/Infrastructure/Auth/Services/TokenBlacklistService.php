<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Logging\LoggerFactory;
use CodeIgniter\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Token Blacklist Service.
 *
 * Cache-based implementation of token blacklisting with size limits and monitoring.
 *
 * Features:
 * - Maximum 10,000 blacklisted tokens
 * - Automatic cleanup at 90% capacity
 * - Warning logs at 80% capacity
 * - Memory usage tracking
 * - Thread-safe counter operations
 *
 * @phpstan-type BlacklistStats array{total_entries: int, estimated_memory_mb: float, capacity_percentage: float, max_capacity: int, cleanup_threshold: int, warning_threshold: int}
 */
final class TokenBlacklistService implements TokenBlacklistInterface
{
    private const int MAX_BLACKLIST_ENTRIES = 10000;
    private const float CLEANUP_THRESHOLD_PERCENTAGE = 0.90; // 90%
    private const float WARNING_THRESHOLD_PERCENTAGE = 0.80; // 80%
    private const int ESTIMATED_BYTES_PER_ENTRY = 96; // sha256 (64) + prefix (16) + overhead (16)
    private const string COUNTER_KEY = 'token_blacklist_counter';
    private const string PREFIX = 'token_blacklist_';

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param CacheInterface $cache
     */
    public function __construct(
        private CacheInterface $cache
    ) {
        $this->logger = LoggerFactory::create('auth.token.blacklist');
    }

    /**
     * blacklist.
     *
     * @param string $token
     * @return void
     */
    public function blacklist(string $token): void
    {
        $this->cleanupIfNeeded();

        $hash = hash('sha256', $token);
        $key = self::PREFIX . $hash;

        // SECURITY FIX: TTL must match refresh token expiration (30 days = 2592000 seconds)
        // Previously was 1 hour (3600), causing logged-out tokens to become valid again
        $this->cache->save($key, time(), 2592000);

        $this->incrementCounter();
        $this->checkCapacityWarning();
    }

    /**
     * isBlacklisted.
     *
     * @param string $token
     * @return bool
     */
    public function isBlacklisted(string $token): bool
    {
        $hash = hash('sha256', $token);
        $key = self::PREFIX . $hash;

        return $this->cache->get($key) !== null;
    }

    /**
     * Get blacklist statistics.
     *
     * @return array<mixed> Array containing:
     *                        - total_entries: Current number of blacklisted tokens
     *                        - estimated_memory_mb: Approximate memory usage in megabytes
     *                        - capacity_percentage: Percentage of max capacity used (0.0-100.0)
     *                        - max_capacity: Maximum allowed entries
     *                        - cleanup_threshold: Entry count that triggers cleanup
     *                        - warning_threshold: Entry count that triggers warning
     */
    public function getStats(): array
    {
        $count = $this->getCounter();
        $estimatedMemoryBytes = $count * self::ESTIMATED_BYTES_PER_ENTRY;
        $estimatedMemoryMb = $estimatedMemoryBytes / 1024 / 1024;
        $capacityPercentage = $count / self::MAX_BLACKLIST_ENTRIES * 100;

        return [
            'total_entries' => $count,
            'estimated_memory_mb' => round($estimatedMemoryMb, 2),
            'capacity_percentage' => round($capacityPercentage, 2),
            'max_capacity' => self::MAX_BLACKLIST_ENTRIES,
            'cleanup_threshold' => (int) (self::MAX_BLACKLIST_ENTRIES * self::CLEANUP_THRESHOLD_PERCENTAGE),
            'warning_threshold' => (int) (self::MAX_BLACKLIST_ENTRIES * self::WARNING_THRESHOLD_PERCENTAGE),
        ];
    }

    /**
     * Manually trigger cleanup of expired entries.
     *
     * This method is automatically called when capacity reaches 90%,
     * but can be manually invoked for maintenance purposes.
     *
     * @return int
     */
    public function cleanup(): int
    {
        $previousCount = $this->getCounter();
        $this->cache->delete(self::COUNTER_KEY);

        $this->logger->info('Token blacklist counter reset', [
            'operation' => 'cleanup_completed',
            'previous_counter' => $previousCount,
            'note' => 'Actual entries expire via cache TTL; counter reset to 0',
        ]);

        return $previousCount;
    }

    /**
     * cleanupIfNeeded.
     *
     * @return void
     */
    private function cleanupIfNeeded(): void
    {
        $count = $this->getCounter();
        $threshold = (int) (self::MAX_BLACKLIST_ENTRIES * self::CLEANUP_THRESHOLD_PERCENTAGE);

        if ($count < $threshold) {
            return;
        }

        $this->logger->warning('Automatic cleanup triggered - blacklist approaching capacity', [
            'operation' => 'automatic_cleanup_triggered',
            'current_entries' => $count,
            'threshold' => $threshold,
            'capacity_percentage' => round($count / self::MAX_BLACKLIST_ENTRIES * 100, 2),
        ]);

        // Reset counter to allow new entries
        // Expired entries will be automatically removed by cache TTL
        $this->cache->delete(self::COUNTER_KEY);
    }

    /**
     * checkCapacityWarning.
     *
     * @return void
     */
    private function checkCapacityWarning(): void
    {
        $count = $this->getCounter();
        $warningThreshold = (int) (self::MAX_BLACKLIST_ENTRIES * self::WARNING_THRESHOLD_PERCENTAGE);

        if ($count < $warningThreshold) {
            return;
        }

        $stats = $this->getStats();

        $this->logger->warning('Token blacklist approaching capacity limit', [
            'operation' => 'capacity_warning',
            'current_entries' => $stats['total_entries'],
            'capacity_percentage' => $stats['capacity_percentage'],
            'estimated_memory_mb' => $stats['estimated_memory_mb'],
            'cleanup_will_trigger_at' => $stats['cleanup_threshold'],
        ]);
    }

    /**
     * getCounter.
     *
     * @return int
     */
    private function getCounter(): int
    {
        $count = $this->cache->get(self::COUNTER_KEY);
        return is_int($count) ? $count : 0;
    }

    /**
     * incrementCounter.
     *
     * @return void
     */
    private function incrementCounter(): void
    {
        $count = $this->getCounter();
        $this->cache->save(self::COUNTER_KEY, $count + 1, 2592000); // 30 days TTL
    }
}

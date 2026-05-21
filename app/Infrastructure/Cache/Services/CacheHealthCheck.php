<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache\Services;

use CodeIgniter\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Cache Health Check Service.
 *
 * Monitors cache backend health and provides fallback recommendations.
 * Automatically validates Redis connectivity and recommends file cache fallback.
 *
 * SECURITY: Prevents cache-related race conditions by ensuring cache backend is operational
 *
 * @package App\Infrastructure\Cache\Services
 */
final readonly class CacheHealthCheck
{
    /**
     * __construct.
     *
     * @param CacheInterface  $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private CacheInterface $cache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Check if cache backend is healthy.
     *
     * @return bool True if cache is operational, false otherwise
     */
    public function isHealthy(): bool
    {
        try {
            $testKey = 'health_check_' . bin2hex(random_bytes(8));
            $testValue = time();

            // Attempt to write to cache
            $writeSuccess = $this->cache->save($testKey, $testValue, 60);

            if (!$writeSuccess) {
                $this->logger->warning('Cache health check failed - write operation failed', [
                    'domain' => 'Cache',
                    'operation' => 'health_check',
                ]);
                return false;
            }

            // Attempt to read from cache
            $readValue = $this->cache->get($testKey);

            if ($readValue !== $testValue) {
                $this->logger->warning('Cache health check failed - read operation returned incorrect value', [
                    'domain' => 'Cache',
                    'operation' => 'health_check',
                    'expected' => $testValue,
                    'actual' => $readValue,
                ]);
                return false;
            }

            // Clean up test key
            $this->cache->delete($testKey);

            $this->logger->debug('Cache health check passed', [
                'domain' => 'Cache',
                'operation' => 'health_check',
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Cache health check failed with exception', [
                'domain' => 'Cache',
                'operation' => 'health_check',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Get cache backend metadata.
     *
     * @return array{handler: string, backup_handler: string, healthy: bool}
     */
    public function getMetadata(): array
    {
        $cacheInfo = $this->cache->getCacheInfo();

        if (!is_array($cacheInfo)) {
            return [
                'handler' => 'unknown',
                'backup_handler' => 'unknown',
                'healthy' => false,
            ];
        }

        return [
            'handler' => $cacheInfo['handler'] ?? 'unknown',
            'backup_handler' => $cacheInfo['backup_handler'] ?? 'unknown',
            'healthy' => $this->isHealthy(),
        ];
    }
}

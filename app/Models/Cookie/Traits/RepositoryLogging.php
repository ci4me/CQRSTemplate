<?php

declare(strict_types=1);

namespace App\Models\Cookie\Traits;

use App\Domain\Cookie\ErrorCodes;

/**
 * Trait for repository logging functionality.
 *
 * Provides logging methods for database operations, slow queries,
 * and business metrics tracking.
 *
 * @package App\Models\Cookie\Traits
 */
trait RepositoryLogging
{
    /**
     * Log save errors.
     */
    private function logSaveError(\Throwable $e): void
    {
        $this->logger->error('Database error during save', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'error_code' => ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED,
            'exception' => $e->getMessage(),
            'exceptionClass' => $e::class,
        ]);
    }

    /**
     * Log delete errors.
     */
    private function logDeleteError(\Throwable $e): void
    {
        $this->logger->error('Database error during delete', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'error_code' => ErrorCodes::COOKIE_REPOSITORY_DELETE_FAILED,
            'exception' => $e->getMessage(),
            'exceptionClass' => $e::class,
        ]);
    }

    /**
     * Log query errors.
     */
    private function logQueryError(string $method, \Throwable $e): void
    {
        $this->logger->error('Query failed', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'method' => $method,
            'error_code' => ErrorCodes::COOKIE_REPOSITORY_QUERY_FAILED,
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * Log slow query if duration exceeds threshold.
     *
     * @param array<string, mixed> $context
     */
    private function logSlowQuery(string $method, float $startTime, int $resultCount, array $context): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($duration <= $this->loggingConfig->slowQueryThresholdMs) {
            return;
        }

        $this->logger->warning('Slow query detected', array_merge([
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'method' => $method,
            'result_count' => $resultCount,
            'duration_ms' => $duration,
            'threshold_ms' => $this->loggingConfig->slowQueryThresholdMs,
        ], $context));
    }

    /**
     * Log slow paginated query.
     *
     * @param array{data: array<int, mixed>, total: int, page: int, perPage: int, lastPage: int} $result
     */
    private function logSlowPaginatedQuery(
        string $method,
        float $startTime,
        array $result,
        int $page,
        int $perPage,
        ?string $searchTerm
    ): void {
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        if ($duration <= $this->loggingConfig->slowQueryThresholdMs) {
            return;
        }

        $this->logger->warning('Slow query detected', [
            'domain' => 'Cookie',
            'repository' => 'CookieRepository',
            'method' => $method,
            'page' => $page,
            'perPage' => $perPage,
            'searchTerm' => $searchTerm,
            'result_count' => count($result['data']),
            'total' => $result['total'],
            'duration_ms' => $duration,
            'threshold_ms' => $this->loggingConfig->slowQueryThresholdMs,
        ]);
    }
}

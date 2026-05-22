<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetAllCookies;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Shared\Ports\LogConfigPort;
use Psr\Log\LoggerInterface;

/**
 * Handler for GetAllCookiesQuery.
 *
 * Responsibilities:
 * 1. Fetch all cookies from repository
 * 2. Optionally filter by active status
 * 3. Return array of cookie entities
 * 4. Log query execution based on configurable logging level
 *
 * Logging Behavior:
 * - 'all': Logs every query execution
 * - 'errors': No error logging (query always succeeds)
 * - 'slow': Logs only when execution exceeds threshold
 * - 'sampling': Logs based on random sampling rate
 * - Slow queries always logged regardless of level
 *
 * @package App\Domain\Cookie\Queries\GetAllCookies
 */
final readonly class GetAllCookiesHandler
{
    /**
     * Create a new GetAllCookiesHandler.
     *
     * @param CookieQueryRepositoryInterface $repository    For data retrieval
     * @param LoggerInterface                $logger        For query logging
     * @param LogConfigPort                  $loggingConfig For logging configuration
     */
    public function __construct(
        private CookieQueryRepositoryInterface $repository,
        private LoggerInterface $logger,
        private LogConfigPort $loggingConfig
    ) {
    }

    /**
     * Handle the GetAllCookiesQuery.
     *
     * @param GetAllCookiesQuery $query The query
     * @return array<int, CookieDTO> Array of cookie DTOs
     */
    public function handle(GetAllCookiesQuery $query): array
    {
        $startTime = microtime(true);

        $cookies = $this->repository->findAll($query->includeInactive);

        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->logQueryExecution($query->includeInactive, count($cookies), $durationMs);

        return $cookies;
    }

    /**
     * Log query execution based on configured logging level.
     *
     * @param bool  $includeInactive Whether inactive cookies were included
     * @param int   $resultCount     Number of cookies returned
     * @param float $durationMs      Execution duration in milliseconds
     */
    private function logQueryExecution(bool $includeInactive, int $resultCount, float $durationMs): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs();

        if ($isSlowQuery) {
            $this->logQuery($includeInactive, $resultCount, $durationMs, true);
            return;
        }

        if (!$this->shouldLogByLevel()) {
            return;
        }

        $this->logQuery($includeInactive, $resultCount, $durationMs, false);
    }

    /**
     * Determine if query should be logged based on configured level.
     *
     * @return bool True if query should be logged
     */
    private function shouldLogByLevel(): bool
    {
        return match ($this->loggingConfig->queryLoggingLevel()) {
            'all' => true,
            'errors' => false,
            'slow' => false,
            'sampling' => $this->shouldSample(),
            default => false,
        };
    }

    /**
     * Log query details with context.
     *
     * @param bool  $includeInactive Whether inactive cookies were included
     * @param int   $resultCount     Number of cookies returned
     * @param float $durationMs      Execution duration in milliseconds
     * @param bool  $isSlowQuery     Whether this is a slow query
     */
    private function logQuery(bool $includeInactive, int $resultCount, float $durationMs, bool $isSlowQuery): void
    {
        $context = [
            'domain' => 'Cookie',
            'query' => 'GetAllCookiesQuery',
            'includeInactive' => $includeInactive,
            'result_count' => $resultCount,
            'duration_ms' => round($durationMs, 2),
        ];

        if ($isSlowQuery) {
            $context['slow_query'] = true;
        }

        $this->logger->info('Query executed', $context);
    }

    /**
     * Determine if query should be sampled for logging.
     *
     * @return bool True if query should be logged based on sampling rate
     */
    private function shouldSample(): bool
    {
        return mt_rand() / mt_getrandmax() < $this->loggingConfig->samplingRate();
    }
}

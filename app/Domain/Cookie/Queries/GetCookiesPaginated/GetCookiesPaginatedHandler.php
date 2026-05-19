<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookiesPaginated;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * Handler for GetCookiesPaginatedQuery.
 *
 * Responsibilities:
 * 1. Fetch paginated cookies from repository
 * 2. Apply search filters if provided
 * 3. Return pagination result with cookies and metadata
 * 4. Log query execution with search analytics
 *
 * Logging Behavior:
 * - 'all': Logs every query execution
 * - 'errors': Logs only when query fails
 * - 'slow': Logs only when execution exceeds threshold
 * - 'sampling': Logs based on random sampling rate
 * - Search queries always logged for analytics (when searchTerm provided)
 * - Slow queries always logged regardless of level
 *
 * @package App\Domain\Cookie\Queries\GetCookiesPaginated
 */
final readonly class GetCookiesPaginatedHandler
{
    /**
     * Create a new GetCookiesPaginatedHandler.
     *
     * @param CookieRepositoryInterface $repository For data retrieval
     * @param LoggerInterface $logger For query logging
     * @param Logging $loggingConfig For logging configuration
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    /**
     * Handle the GetCookiesPaginatedQuery.
     *
     * @param GetCookiesPaginatedQuery $query The query
     * @return array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int} Pagination result
     */
    public function handle(GetCookiesPaginatedQuery $query): array
    {
        $startTime = microtime(true);

        $result = $this->repository->findPaginated(
            page: $query->page,
            perPage: $query->perPage,
            searchTerm: $query->searchTerm,
            includeInactive: $query->includeInactive
        );

        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->logQueryExecution($query, $result, $durationMs);

        return $result;
    }

    /**
     * Log query execution based on configured logging level.
     *
     * @param GetCookiesPaginatedQuery $query The query being executed
     * @param array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int} $result The query result
     * @param float $durationMs Execution duration in milliseconds
     */
    private function logQueryExecution(GetCookiesPaginatedQuery $query, array $result, float $durationMs): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs;
        $isSearchQuery = $query->searchTerm !== null && $query->searchTerm !== '';

        if ($isSlowQuery || $isSearchQuery) {
            $this->logQuery($query, $result, $durationMs, $isSlowQuery);
            return;
        }

        $shouldLog = match ($this->loggingConfig->queryLoggingLevel) {
            'all' => true,
            'errors' => false,
            'slow' => false,
            'sampling' => $this->shouldSample(),
            default => false,
        };

        if (!$shouldLog) {
            return;
        }

        $this->logQuery($query, $result, $durationMs, false);
    }

    /**
     * Log query details with context.
     *
     * @param GetCookiesPaginatedQuery $query The query being executed
     * @param array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int} $result The query result
     * @param float $durationMs Execution duration in milliseconds
     * @param bool $isSlowQuery Whether this is a slow query
     */
    private function logQuery(
        GetCookiesPaginatedQuery $query,
        array $result,
        float $durationMs,
        bool $isSlowQuery
    ): void {
        $context = [
            'domain' => 'Cookie',
            'query' => 'GetCookiesPaginatedQuery',
            'page' => $query->page,
            'perPage' => $query->perPage,
            'result_count' => count($result['data']),
            'total' => $result['total'],
            'duration_ms' => round($durationMs, 2),
        ];

        if ($query->searchTerm !== null && $query->searchTerm !== '') {
            $context['searchTerm'] = $query->searchTerm;
        }

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
        return mt_rand() / mt_getrandmax() < $this->loggingConfig->samplingRate;
    }
}

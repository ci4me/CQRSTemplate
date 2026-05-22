<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookieById;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Ports\LogConfigPort;
use Psr\Log\LoggerInterface;

/**
 * Handler for GetCookieByIdQuery.
 *
 * Responsibilities:
 * 1. Fetch cookie from repository by ID
 * 2. Return cookie entity or null
 * 3. Log query execution based on configurable logging level
 *
 * Logging Behavior:
 * - 'all': Logs every query execution
 * - 'errors': Logs only when cookie not found
 * - 'slow': Logs only when execution exceeds threshold
 * - 'sampling': Logs based on random sampling rate
 * - Slow queries always logged regardless of level
 *
 * Note: Returns null if cookie not found instead of throwing exception.
 * The controller can decide how to handle not found cases.
 *
 * @package App\Domain\Cookie\Queries\GetCookieById
 * @implements QueryHandlerInterface<GetCookieByIdQuery, CookieDTO|null>
 */
final readonly class GetCookieByIdHandler implements QueryHandlerInterface
{
    /**
     * Create a new GetCookieByIdHandler.
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
     * Handle the GetCookieByIdQuery.
     *
     * @param GetCookieByIdQuery $query The query
     * @return CookieDTO|null The cookie DTO or null if not found
     */
    public function handle(object $query): ?CookieDTO
    {
        $startTime = microtime(true);

        $dto = $this->repository->findById($query->id);

        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->logQueryExecution($query->id, $dto, $durationMs);

        return $dto;
    }

    /**
     * Log query execution based on configured logging level.
     *
     * @param int            $cookieId   The cookie ID being queried
     * @param CookieDTO|null $result     The query result
     * @param float          $durationMs Execution duration in milliseconds
     */
    private function logQueryExecution(int $cookieId, ?CookieDTO $result, float $durationMs): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs();

        if ($isSlowQuery) {
            $this->logQuery($cookieId, $result, $durationMs, true);
            return;
        }

        $shouldLog = match ($this->loggingConfig->queryLoggingLevel()) {
            'all' => true,
            'errors' => $result === null,
            'slow' => false,
            'sampling' => $this->shouldSample(),
            default => false,
        };

        if (!$shouldLog) {
            return;
        }

        $this->logQuery($cookieId, $result, $durationMs, false);
    }

    /**
     * Log query details with context.
     *
     * @param int            $cookieId    The cookie ID being queried
     * @param CookieDTO|null $result      The query result
     * @param float          $durationMs  Execution duration in milliseconds
     * @param bool           $isSlowQuery Whether this is a slow query
     */
    private function logQuery(int $cookieId, ?CookieDTO $result, float $durationMs, bool $isSlowQuery): void
    {
        $context = [
            'domain' => 'Cookie',
            'query' => 'GetCookieByIdQuery',
            'cookieId' => $cookieId,
            'result' => $result === null ? 'not_found' : 'found',
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
     * Delegates to the shared {@see LogSampler}, which uses random_int
     * (CSPRNG) instead of the biased Mersenne Twister the previous
     * implementation called (closes 04/F12, 14/F20, 17/F2). Constructing
     * the sampler per-call is cheap (single integer cast); E08 lifts
     * this to constructor injection when migrating onto AbstractQueryHandler.
     *
     * @return bool True if query should be logged based on sampling rate
     */
    private function shouldSample(): bool
    {
        return (new LogSampler($this->loggingConfig->samplingRate()))->shouldSample();
    }
}

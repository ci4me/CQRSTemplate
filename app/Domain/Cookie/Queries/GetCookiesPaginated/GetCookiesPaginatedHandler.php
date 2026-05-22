<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookiesPaginated;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Shared\Bus\AbstractQueryHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Ports\LogConfigPort;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see GetCookiesPaginatedQuery}.
 *
 * Post-E08:
 *  - Boilerplate lives in {@see AbstractQueryHandler}.
 *  - The query DTO is responsible for bounding `$page` + LIKE-escaping
 *    `$searchTerm`; the handler just forwards the safe values to the
 *    repository (closes 04/F4 + 04/F6 consumption).
 *  - {@see shouldLog()} force-logs search queries regardless of level so
 *    search analytics survive the move to the abstract base.
 *
 * @package App\Domain\Cookie\Queries\GetCookiesPaginated
 * @implements QueryHandlerInterface<GetCookiesPaginatedQuery, array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}>
 */
final class GetCookiesPaginatedHandler extends AbstractQueryHandler implements QueryHandlerInterface
{
    /**
     * @param CookieQueryRepositoryInterface $repository    Read-side port returning DTOs.
     * @param LoggerInterface                $logger        PSR-3 logger (channel: cookie.query.paginated).
     * @param ClockInterface                 $clock         Monotonic time source for duration.
     * @param LogSampler                     $sampler       Shared sampling policy.
     * @param LogConfigPort                  $loggingConfig Per-handler logging-level policy.
     */
    public function __construct(
        private readonly CookieQueryRepositoryInterface $repository,
        LoggerInterface $logger,
        ClockInterface $clock,
        LogSampler $sampler,
        private readonly LogConfigPort $loggingConfig
    ) {
        parent::__construct($logger, $clock, $sampler);
    }

    /**
     * @param GetCookiesPaginatedQuery $query The query DTO.
     * @return array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}
     */
    protected function doHandle(object $query): array
    {
        return $this->repository->findPaginated(
            page: $query->page,
            perPage: $query->perPage,
            searchTerm: $query->searchTerm,
            includeInactive: $query->includeInactive,
        );
    }

    /**
     * Force-log search queries even when the level is 'errors' or 'slow'
     * (search analytics is a separate concern from operational logging).
     *
     * @param GetCookiesPaginatedQuery $query
     * @param array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int} $result
     */
    protected function shouldLog(object $query, mixed $result, float $durationMs): bool
    {
        unset($result);
        if ($this->isSlowQuery($durationMs)) {
            return true;
        }
        if ($query->searchTerm !== null && $query->searchTerm !== '') {
            return true;
        }

        return match ($this->loggingConfig->queryLoggingLevel()) {
            'all' => true,
            'sampling' => $this->sampler->shouldSample(),
            default => false,
        };
    }

    /**
     * @param GetCookiesPaginatedQuery $query
     * @param array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int} $result
     * @return array<string, scalar|null>
     */
    protected function logContext(object $query, mixed $result): array
    {
        $context = [
            'domain' => $this->getDomain(),
            'query' => $this->queryClass(),
            'page' => $query->page,
            'perPage' => $query->perPage,
            'result_count' => count($result['data']),
            'total' => $result['total'],
        ];

        if ($query->searchTerm !== null && $query->searchTerm !== '') {
            $context['searchTerm'] = $query->searchTerm;
        }

        return $context;
    }

    protected function getDomain(): string
    {
        return 'Cookie';
    }

    protected function queryClass(): string
    {
        return GetCookiesPaginatedQuery::class;
    }

    protected function slowQueryThresholdMs(): int
    {
        return $this->loggingConfig->slowQueryThresholdMs();
    }
}

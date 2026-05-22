<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetAllCookies;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Shared\Bus\AbstractQueryHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Ports\LogConfigPort;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see GetAllCookiesQuery}.
 *
 * Post-E08 the boilerplate lives in {@see AbstractQueryHandler}. The
 * handler body:
 *  - Loads via the read-side repo.
 *  - Asserts the response size respects {@see GetAllCookiesQuery::MAX_RESULTS}
 *    — closes 04/F2.
 *  - Layers the LogConfigPort policy on top of the base via
 *    {@see shouldLog()}.
 *
 * @package App\Domain\Cookie\Queries\GetAllCookies
 * @implements QueryHandlerInterface<GetAllCookiesQuery, list<CookieDTO>>
 */
final class GetAllCookiesHandler extends AbstractQueryHandler implements QueryHandlerInterface
{
    /**
     * @param CookieQueryRepositoryInterface $repository    Read-side port returning DTOs.
     * @param LoggerInterface                $logger        PSR-3 logger (channel: cookie.query.getAll).
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
     * @param GetAllCookiesQuery $query The query DTO.
     * @return list<CookieDTO> Array of cookie DTOs.
     * @throws ValidationException When the repository returned more than
     *                             {@see GetAllCookiesQuery::MAX_RESULTS}.
     */
    protected function doHandle(object $query): array
    {
        $cookies = $this->repository->findAll($query->includeInactive);
        $count = count($cookies);
        if ($count > GetAllCookiesQuery::MAX_RESULTS) {
            throw ValidationException::outOfRange(
                'result_count',
                0,
                GetAllCookiesQuery::MAX_RESULTS,
                $count,
                ErrorCodes::COOKIE_QUERY_RESULT_LIMIT_EXCEEDED
            );
        }

        return $cookies;
    }

    /**
     * @param GetAllCookiesQuery $query
     * @param list<CookieDTO>    $result
     */
    protected function shouldLog(object $query, mixed $result, float $durationMs): bool
    {
        unset($query, $result);
        if ($this->isSlowQuery($durationMs)) {
            return true;
        }

        return match ($this->loggingConfig->queryLoggingLevel()) {
            'all' => true,
            'sampling' => $this->sampler->shouldSample(),
            default => false,
        };
    }

    /**
     * @param GetAllCookiesQuery $query
     * @param list<CookieDTO>    $result
     * @return array<string, scalar|null>
     */
    protected function logContext(object $query, mixed $result): array
    {
        return [
            'domain' => $this->getDomain(),
            'query' => $this->queryClass(),
            'includeInactive' => $query->includeInactive,
            'result_count' => count($result),
        ];
    }

    protected function getDomain(): string
    {
        return 'Cookie';
    }

    protected function queryClass(): string
    {
        return GetAllCookiesQuery::class;
    }

    protected function slowQueryThresholdMs(): int
    {
        return $this->loggingConfig->slowQueryThresholdMs();
    }
}

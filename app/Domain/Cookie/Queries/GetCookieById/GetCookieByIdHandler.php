<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Queries\GetCookieById;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Shared\Bus\AbstractQueryHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\LogSampler;
use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Ports\LogConfigPort;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see GetCookieByIdQuery}.
 *
 * Post-E08 the timing + slow-query promotion + sampling decision lives
 * in {@see AbstractQueryHandler}. This subclass:
 *  - Loads the DTO in {@see doHandle()} — that's the whole business
 *    surface.
 *  - Overrides {@see shouldLog()} to layer the {@see LogConfigPort}
 *    policy on top of the base default (slow OR sampled). The 'errors'
 *    level force-logs `null` results because not-found is the only
 *    interesting error condition for this query (closes 04/F1
 *    consumption).
 *  - Overrides {@see logContext()} to add `cookieId` + `result` so the
 *    log line carries the shape operators expect.
 *
 * @package App\Domain\Cookie\Queries\GetCookieById
 * @implements QueryHandlerInterface<GetCookieByIdQuery, CookieDTO|null>
 */
final class GetCookieByIdHandler extends AbstractQueryHandler implements QueryHandlerInterface
{
    /**
     * @param CookieQueryRepositoryInterface $repository    Read-side port returning DTOs.
     * @param LoggerInterface                $logger        PSR-3 logger (channel: cookie.query.getById).
     * @param ClockInterface                 $clock         Monotonic time source for duration.
     * @param LogSampler                     $sampler       Shared sampling policy (random_int-backed).
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
     * @param GetCookieByIdQuery $query The query DTO.
     * @return CookieDTO|null The cookie DTO or null if not found.
     */
    protected function doHandle(object $query): ?CookieDTO
    {
        return $this->repository->findById($query->id);
    }

    /**
     * Layer the LogConfigPort policy on top of the base "slow OR sampled".
     *
     * 'errors' fires when result is null (the only error condition we
     * surface); 'all' always logs; 'slow' never logs from this branch
     * (slow queries are caught by the parent's isSlowQuery check);
     * 'sampling' delegates to the parent's sampler.
     *
     * @param GetCookieByIdQuery $query
     * @param CookieDTO|null     $result
     */
    protected function shouldLog(object $query, mixed $result, float $durationMs): bool
    {
        unset($query);
        if ($this->isSlowQuery($durationMs)) {
            return true;
        }

        return match ($this->loggingConfig->queryLoggingLevel()) {
            'all' => true,
            'errors' => $result === null,
            'sampling' => $this->sampler->shouldSample(),
            default => false,
        };
    }

    /**
     * @param GetCookieByIdQuery $query
     * @param CookieDTO|null     $result
     * @return array<string, scalar|null>
     */
    protected function logContext(object $query, mixed $result): array
    {
        return [
            'domain' => $this->getDomain(),
            'query' => $this->queryClass(),
            'cookieId' => $query->id,
            'result' => $result === null ? 'not_found' : 'found',
        ];
    }

    protected function getDomain(): string
    {
        return 'Cookie';
    }

    protected function queryClass(): string
    {
        return GetCookieByIdQuery::class;
    }

    /**
     * Delegate to the injected {@see LogConfigPort} so operators can dial
     * the threshold at runtime without touching code.
     */
    protected function slowQueryThresholdMs(): int
    {
        return $this->loggingConfig->slowQueryThresholdMs();
    }
}

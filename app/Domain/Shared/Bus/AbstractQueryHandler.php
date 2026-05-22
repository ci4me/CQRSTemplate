<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

use Psr\Log\LoggerInterface;

/**
 * Template Method base for query handlers.
 *
 * Counterpart to {@see AbstractCommandHandler} on the read side. Extracts
 * the three identical "time the query → escalate slow queries → otherwise
 * decide via the logging-level policy" blocks the Cookie query handlers
 * each carried (closes 04/F1 shape, 14/F3).
 *
 * Cross-cutting policy in one place:
 *  - Slow queries log at `warning`, not `info` (closes 04/F7). The
 *    previous handlers logged slow queries at info, which made them
 *    indistinguishable from healthy queries when triaging alerts.
 *  - Sampling delegates to {@see LogSampler}, which uses `random_int`
 *    (CSPRNG) instead of `mt_rand()` (closes 04/F12, 14/F20, 17/F2).
 *  - Single timing source via {@see ClockInterface} (closes 14/F21).
 *  - Optional cache seam (`cacheKey()` / `cacheTtlSeconds()`) prepared
 *    for E10 — subclasses opt in by overriding (closes 04/F11 shape).
 *
 * Subclass contract:
 *   protected function doHandle(object $query): mixed
 *   protected function getDomain(): string  // e.g. 'Cookie'
 *   protected function queryClass(): string  // GetCookieByIdQuery::class
 *
 * Optional overrides:
 *   protected function shouldLog(...): bool  // default: slow|all|sampling
 *   protected function slowQueryThresholdMs(): int  // default: 500
 *   protected function logContext($query, $result): array
 *
 * Methods stay ≤ 20 lines each.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractQueryHandler
{
    /**
     * @param LoggerInterface $logger  PSR-3 logger (channel chosen by subclass).
     * @param ClockInterface  $clock   Monotonic timing source.
     * @param LogSampler      $sampler Shared sampling policy (random_int-backed).
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ClockInterface $clock,
        protected LogSampler $sampler,
    ) {
    }

    /**
     * Orchestration template. Times the query, then decides whether to
     * log + at what level. `final` so subclasses can't bypass timing.
     *
     * @param object $query The query DTO.
     * @return mixed Result returned by doHandle().
     */
    final public function handle(object $query): mixed
    {
        $startTime = $this->clock->now();
        $result = $this->doHandle($query);
        $durationMs = ($this->clock->now() - $startTime) * 1000;

        $this->logQueryExecution($query, $result, $durationMs);

        return $result;
    }

    /**
     * Subclass-specific data fetch logic.
     *
     * @param object $query The (narrowed) query DTO.
     * @return mixed Read-model returned to the caller.
     */
    abstract protected function doHandle(object $query): mixed;

    /**
     * Domain label used in log payloads (e.g. 'Cookie').
     */
    abstract protected function getDomain(): string;

    /**
     * FQCN of the query class this handler accepts.
     */
    abstract protected function queryClass(): string;

    /**
     * Decide whether the query should be logged + at which level.
     *
     * Subclasses CAN override to honour a per-handler policy port (e.g.
     * LogConfigPort). Default behaviour: log slow queries at `warning`,
     * other queries at `info` only when the sampler fires.
     *
     * @param float $durationMs Wall time.
     */
    protected function shouldLog(float $durationMs): bool
    {
        return $this->isSlowQuery($durationMs) || $this->sampler->shouldSample();
    }

    /**
     * Slow-query threshold. Default 500 ms; override to widen / narrow.
     */
    protected function slowQueryThresholdMs(): int
    {
        return 500;
    }

    /**
     * @param float $durationMs Measured wall time.
     */
    protected function isSlowQuery(float $durationMs): bool
    {
        return $durationMs > $this->slowQueryThresholdMs();
    }

    /**
     * Build the log payload. Subclasses override to add result-shape
     * fields (e.g. result_count, searchTerm) — call parent::logContext()
     * to keep the base keys.
     *
     * @param object $query  The query DTO.
     * @param mixed  $result The doHandle return value.
     * @return array<string, scalar|null> Log fields.
     */
    protected function logContext(object $query, mixed $result): array
    {
        unset($query, $result);

        return [
            'domain' => $this->getDomain(),
            'query' => $this->queryClass(),
        ];
    }

    /**
     * Optional cache key for the query result. Default: no caching.
     * Subclasses opt in by returning a stable identifier (e.g. an MD5
     * hash of the query's discriminators). E10 wires this into a
     * cache-aside read-through adapter.
     */
    protected function cacheKey(object $query): ?string
    {
        unset($query);

        return null;
    }

    /**
     * Default cache TTL in seconds; 0 disables caching even if
     * cacheKey() returns a non-null value.
     */
    protected function cacheTtlSeconds(): int
    {
        return 0;
    }

    /**
     * Apply the policy + emit the actual log line. Slow queries always
     * log at `warning`; sampled queries log at `info`.
     *
     * @param object $query      The query DTO.
     * @param mixed  $result     The result returned by doHandle().
     * @param float  $durationMs Measured wall time.
     */
    private function logQueryExecution(object $query, mixed $result, float $durationMs): void
    {
        $isSlow = $this->isSlowQuery($durationMs);

        if (!$isSlow && !$this->sampler->shouldSample()) {
            return;
        }

        $context = $this->logContext($query, $result);
        $context['duration_ms'] = round($durationMs, 2);

        if ($isSlow) {
            $context['slow_query'] = true;
            $this->logger->warning('Slow query executed', $context);

            return;
        }

        $this->logger->info('Query executed', $context);
    }
}

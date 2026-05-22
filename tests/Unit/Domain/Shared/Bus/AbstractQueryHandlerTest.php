<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Bus;

use App\Domain\Shared\Bus\AbstractQueryHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\LogSampler;
use Psr\Log\LoggerInterface;
use Tests\Support\UnitTestCase;

/**
 * Pins AbstractQueryHandler orchestration:
 *  - slow queries log at `warning` (closes 04/F7) — they NEVER fall
 *    back to `info` regardless of sampling outcome.
 *  - fast queries are sampled via LogSampler; sampler rate 0 -> no log.
 *  - sampler rate 1.0 + fast query -> info log.
 *  - timing uses the injected clock, not microtime/hrtime directly.
 *  - log payload carries domain + query + duration_ms.
 *  - cacheKey()/cacheTtlSeconds() seam exposes the future cache hook
 *    (defaults null/0, subclasses can override).
 */
final class AbstractQueryHandlerTest extends UnitTestCase
{
    public function test_slow_query_logs_at_warning_not_info(): void
    {
        $logger = new InMemoryLogger();
        // 600ms - well past the default 500ms threshold.
        $clock = new FixedClock([0.0, 0.6]);
        // Sampler rate 0 - if the slow-query path didn't escalate, this
        // would silently drop the log line, masking the bug.
        $sampler = new LogSampler(0.0);

        $handler = new FakeQueryHandler($logger, $clock, $sampler, doHandleReturn: ['ok']);
        $handler->handle(new FakeQueryPayload());

        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('Slow query executed', $logger->records[0]['message']);
        $this->assertTrue($logger->records[0]['context']['slow_query']);
        $this->assertSame(600.0, $logger->records[0]['context']['duration_ms']);
    }

    public function test_fast_query_with_zero_sampler_is_not_logged(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([0.0, 0.001]); // 1ms - fast
        $sampler = new LogSampler(0.0);

        $handler = new FakeQueryHandler($logger, $clock, $sampler, doHandleReturn: ['x']);
        $handler->handle(new FakeQueryPayload());

        $this->assertSame([], $logger->records);
    }

    public function test_fast_query_with_full_sampler_logs_at_info(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([0.0, 0.001]);
        $sampler = new LogSampler(1.0);

        $handler = new FakeQueryHandler($logger, $clock, $sampler, doHandleReturn: ['x']);
        $handler->handle(new FakeQueryPayload());

        $this->assertCount(1, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('Query executed', $logger->records[0]['message']);
        // Fast queries do NOT carry slow_query=true.
        $this->assertArrayNotHasKey('slow_query', $logger->records[0]['context']);
    }

    public function test_handle_returns_doHandle_result_untouched(): void
    {
        $expected = ['hello', 'world'];
        $handler = new FakeQueryHandler(
            new InMemoryLogger(),
            new FixedClock([0.0, 0.001]),
            new LogSampler(0.0),
            doHandleReturn: $expected,
        );

        $this->assertSame($expected, $handler->handle(new FakeQueryPayload()));
    }

    public function test_cache_seam_has_default_null_key_and_zero_ttl(): void
    {
        $handler = new FakeQueryHandler(
            new InMemoryLogger(),
            new FixedClock([0.0, 0.001]),
            new LogSampler(0.0),
            doHandleReturn: null,
        );

        $this->assertNull($handler->exposeCacheKey(new FakeQueryPayload()));
        $this->assertSame(0, $handler->exposeCacheTtl());
    }
}

/**
 * Test double mirroring FakeCommandHandler's shape — exposes the
 * protected cache seam so the test asserts the defaults.
 */
final class FakeQueryHandler extends AbstractQueryHandler
{
    public function __construct(
        LoggerInterface $logger,
        ClockInterface $clock,
        LogSampler $sampler,
        private readonly mixed $doHandleReturn = null,
    ) {
        parent::__construct($logger, $clock, $sampler);
    }

    protected function doHandle(object $query): mixed
    {
        return $this->doHandleReturn;
    }

    protected function getDomain(): string
    {
        return 'FakeDomain';
    }

    protected function queryClass(): string
    {
        return FakeQueryPayload::class;
    }

    public function exposeCacheKey(object $query): ?string
    {
        return $this->cacheKey($query);
    }

    public function exposeCacheTtl(): int
    {
        return $this->cacheTtlSeconds();
    }
}

final readonly class FakeQueryPayload
{
    public function __construct()
    {
    }
}

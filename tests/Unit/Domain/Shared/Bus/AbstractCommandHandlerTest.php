<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Bus;

use App\Domain\Shared\Bus\AbstractCommandHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\UnitTestCase;

/**
 * Pins AbstractCommandHandler orchestration:
 *  - happy path logs start + success with duration_ms.
 *  - failure path logs error + rethrows the original exception unchanged.
 *  - DomainException::getErrorCode() (when set) propagates into the
 *    log payload (NO str_contains-on-message).
 *  - durationMs comes from the injected clock, not microtime.
 */
final class AbstractCommandHandlerTest extends UnitTestCase
{
    public function test_happy_path_logs_start_and_success(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([100.0, 100.5]); // 500ms

        $handler = new FakeCommandHandler($logger, $clock, doHandleReturn: 42);
        $result = $handler->handle(new FakeCommandPayload('payload'));

        $this->assertSame(42, $result);
        $this->assertCount(2, $logger->records);
        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertSame('Handling FakeCommandPayload', $logger->records[0]['message']);
        $this->assertSame('FakeCommandPayload handled successfully', $logger->records[1]['message']);
        $this->assertSame(500.0, $logger->records[1]['context']['duration_ms']);
        $this->assertSame(42, $logger->records[1]['context']['resultId']);
        $this->assertSame('FakeDomain', $logger->records[1]['context']['domain']);
    }

    public function test_failure_path_logs_error_and_rethrows(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([10.0, 10.125]); // 125ms
        $boom = DomainException::businessRuleViolation('x', 'y', 9001);

        $handler = new FakeCommandHandler($logger, $clock, throw: $boom);

        try {
            $handler->handle(new FakeCommandPayload('p'));
            $this->fail('exception should have propagated');
        } catch (\Throwable $thrown) {
            $this->assertSame($boom, $thrown, 'must rethrow the original exception');
        }

        $this->assertCount(2, $logger->records);
        $this->assertSame('error', $logger->records[1]['level']);
        $this->assertSame(9001, $logger->records[1]['context']['error_code']);
        $this->assertSame(125.0, $logger->records[1]['context']['duration_ms']);
        $this->assertSame(DomainException::class, $logger->records[1]['context']['exceptionClass']);
    }

    public function test_validation_exception_error_code_propagates(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([0.0, 0.001]);
        $boom = new ValidationException('bad', ['field' => ['err']], 7777);

        $handler = new FakeCommandHandler($logger, $clock, throw: $boom);

        try {
            $handler->handle(new FakeCommandPayload('p'));
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(7777, $logger->records[1]['context']['error_code']);
    }

    public function test_exception_without_error_code_falls_back_to_default(): void
    {
        $logger = new InMemoryLogger();
        $clock = new FixedClock([0.0, 0.001]);
        $boom = new \RuntimeException('something went wrong');

        $handler = new FakeCommandHandler($logger, $clock, throw: $boom, defaultErrorCode: 8400);

        try {
            $handler->handle(new FakeCommandPayload('p'));
        } catch (\Throwable) {
            // expected
        }

        // RuntimeException carries no getErrorCode() — falls back to the
        // subclass-provided default. CRUCIALLY: the resolver does NOT
        // inspect the exception message (no str_contains).
        $this->assertSame(8400, $logger->records[1]['context']['error_code']);
    }

    public function test_clock_is_only_source_of_duration(): void
    {
        $logger = new InMemoryLogger();
        // Deliberately weird clock values to prove microtime() is unused.
        $clock = new FixedClock([1_000_000.0, 1_000_000.42]);

        $handler = new FakeCommandHandler($logger, $clock, doHandleReturn: 1);
        $handler->handle(new FakeCommandPayload('p'));

        $this->assertSame(420.0, $logger->records[1]['context']['duration_ms']);
    }
}

/**
 * Test double — concrete subclass that delegates doHandle to a closure
 * provided at construction time, so each test can exercise success +
 * failure paths without inheritance gymnastics.
 */
final class FakeCommandHandler extends AbstractCommandHandler
{
    public function __construct(
        LoggerInterface $logger,
        ClockInterface $clock,
        private readonly mixed $doHandleReturn = null,
        private readonly ?\Throwable $throw = null,
        private readonly int $defaultErrorCode = 0,
    ) {
        parent::__construct($logger, $clock);
    }

    protected function doHandle(object $command): mixed
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->doHandleReturn;
    }

    protected function getDomain(): string
    {
        return 'FakeDomain';
    }

    protected function commandClass(): string
    {
        return FakeCommandPayload::class;
    }

    protected function defaultErrorCode(): int
    {
        return $this->defaultErrorCode;
    }
}

final readonly class FakeCommandPayload
{
    public function __construct(public string $payload)
    {
    }
}

/**
 * Pre-programmed clock — pops the next value off `$ticks` on each call.
 */
final class FixedClock implements ClockInterface
{
    /** @var list<float> */
    private array $ticks;

    /** @param list<float> $ticks */
    public function __construct(array $ticks)
    {
        $this->ticks = $ticks;
    }

    public function now(): float
    {
        if ($this->ticks === []) {
            throw new \LogicException('FixedClock exhausted — add more ticks for this test');
        }
        return array_shift($this->ticks);
    }
}

/**
 * Minimal PSR-3 logger that records each call for later assertion.
 * Avoids the cost of bringing PHPUnit's MockBuilder + setMockBuilder
 * dance into a hot test path.
 */
final class InMemoryLogger extends NullLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

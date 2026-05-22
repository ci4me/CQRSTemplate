<?php

declare(strict_types=1);

namespace App\Domain\Shared\Bus;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Template Method base for command handlers.
 *
 * Extracts the ~70-line "log start → time → handle → log success or
 * log failure + rethrow" boilerplate that each of the four Cookie
 * command handlers carried independently (closes 03/F3 shape, 03/F6
 * shape, 14/F1). Subclasses focus on the actual business logic via the
 * narrow {@see doHandle()} method.
 *
 * Generic parameters mirror {@see CommandHandlerInterface}: a subclass
 * declares `extends AbstractCommandHandler` and implements
 * `CommandHandlerInterface<CreateCookieCommand, int>`.
 *
 * Cross-cutting policy in one place:
 *  - Single timing source via {@see ClockInterface} (closes 03/F11,
 *    14/F21, 17/P3 — replaces the mix of microtime/hrtime calls).
 *  - Error-code resolution via the exception's own `getErrorCode()`,
 *    NEVER via `str_contains($e->getMessage(), ...)` (closes 03/F4,
 *    14/F2). The previous resolver coupled error codes to the prose
 *    of the message, so any wording change silently changed the code.
 *  - `domain` / `command` log keys are derived from the abstract
 *    `getDomain()` / `commandClass()` overrides instead of being
 *    hard-coded strings (closes 03/F12, 03/F14, 14/F12 partial).
 *
 * Subclass contract:
 *   protected function getDomain(): string  // e.g. 'Cookie'
 *   protected function commandClass(): string  // CreateCookieCommand::class
 *   protected function doHandle(object $command): mixed
 *
 * Methods in this class are intentionally short (≤ 20 lines each, per
 * CLAUDE.md). The orchestration lives in `final handle()`; everything
 * else is a single-purpose helper subclasses can extend.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractCommandHandler
{
    /**
     * @param LoggerInterface $logger PSR-3 logger; subclasses pass their
     *                                channel-specific logger up.
     * @param ClockInterface  $clock  Monotonic timing source.
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ClockInterface $clock,
    ) {
    }

    /**
     * Orchestration template — final so subclasses can't bypass logging.
     *
     * Sequence: log start → record start time → delegate to doHandle →
     * log success → return result. On any Throwable: log failure with
     * duration + resolved error code, then rethrow unchanged.
     *
     * @param object $command The command DTO.
     * @return mixed The result returned by doHandle().
     * @throws \Throwable Re-thrown after logging; callers handle.
     */
    final public function handle(object $command): mixed
    {
        $startTime = $this->clock->now();
        $this->logStart($command);

        try {
            $result = $this->doHandle($command);
            $this->postCommit($command, $result);
            $durationMs = $this->durationMs($startTime);
            $this->logSuccess($durationMs, $this->resultId($result));

            return $result;
        } catch (\Throwable $e) {
            $this->logFailure($e, $this->durationMs($startTime));
            throw $e;
        }
    }

    /**
     * Hook called AFTER {@see doHandle()} returns successfully and BEFORE
     * the success line is logged.
     *
     * Concrete subclasses use this to drain entity-raised events from an
     * aggregate the doHandle returned (or referenced) and hand them to
     * the dispatcher, so the call site for {@see \App\Domain\Shared\Aggregate\AggregateRoot::pullEvents()}
     * lives in exactly one place per handler. By keeping the drain INSIDE
     * the timing window we ensure event dispatch latency shows up in the
     * handler's `duration_ms` figure rather than being invisible.
     *
     * Critical invariant: implementations MUST drain only ONCE — the
     * repository no longer drains (E07), so the handler's drain is the
     * single source of dispatch. Closes 03/F1.
     *
     * The default implementation is a no-op so handlers that don't deal
     * with an aggregate (e.g. CreateCookieHandler, which dispatches its
     * event manually because the entity hasn't been persisted yet at the
     * point the id becomes known) can ignore this hook.
     *
     * @param object $command The command DTO (for context, mostly unused).
     * @param mixed  $result  Whatever doHandle returned (e.g. an int id).
     */
    protected function postCommit(object $command, mixed $result): void
    {
        unset($command, $result);
    }

    /**
     * Helper used by subclass postCommit() implementations: drain every
     * event the aggregate raised + dispatch each via the subclass's own
     * dispatcher reference.
     *
     * Lives on the base so the drain shape is consistent across every
     * Cookie handler — and so future domains can reuse it without
     * re-implementing the `foreach … dispatch` loop. The base does NOT
     * own the dispatcher (constructor injection happens in the subclass
     * because not every handler needs one), which is why this method is
     * intentionally a static helper rather than an instance method
     * pulling from a base-level field.
     *
     * @param iterable<object>                                     $events     Output of `$aggregate->pullEvents()`.
     * @param \App\Domain\Shared\Events\EventDispatcherInterface  $dispatcher Concrete dispatcher (typically injected
     *                                                                         on the subclass).
     */
    final protected function dispatchPulledEvents(
        iterable $events,
        \App\Domain\Shared\Events\EventDispatcherInterface $dispatcher
    ): void {
        foreach ($events as $event) {
            $dispatcher->dispatch($event);
        }
    }

    /**
     * Subclass-specific business logic. Called from {@see handle()}.
     *
     * The native return type is intentionally OMITTED for the same reason
     * {@see CommandHandlerInterface::handle()} omits it: concrete handlers
     * declare their precise return shape (`int` for Create*, `void` for
     * the others) without tripping PHP's incompatibility rule between
     * `void` and `mixed`. PHPStan recovers the precise return via the
     * subclass's own `@return` tag.
     *
     * @param object $command The (narrowed) command DTO.
     * @return mixed Subclass-specific result (int / void / value object).
     */
    // phpcs:disable SlevomatCodingStandard.TypeHints.ReturnTypeHint
    abstract protected function doHandle(object $command);
    // phpcs:enable SlevomatCodingStandard.TypeHints.ReturnTypeHint

    /**
     * Domain label used in log payloads (e.g. 'Cookie').
     */
    abstract protected function getDomain(): string;

    /**
     * Short class name of the command this handler accepts.
     *
     * Used as the 'command' key in log payloads. Subclasses typically
     * return `CreateCookieCommand::class`-style FQCNs; the bus log
     * payload preserves the FQCN to avoid collisions between domains.
     */
    abstract protected function commandClass(): string;

    /**
     * Log the "starting" line. Subclasses extend by overriding
     * {@see logContext()} to add command-specific fields.
     *
     * @param object $command The command (unused here; subclasses may need
     *                        it when extending logStart).
     */
    protected function logStart(object $command): void
    {
        unset($command);
        $this->logger->info(
            sprintf('Handling %s', $this->shortName($this->commandClass())),
            $this->logContext()
        );
    }

    /**
     * Log the success line with duration and optional result id.
     *
     * @param float    $durationMs Wall time in ms.
     * @param int|null $resultId   Aggregate id (Create*) or null.
     */
    protected function logSuccess(float $durationMs, ?int $resultId = null): void
    {
        $context = $this->logContext();
        $context['duration_ms'] = round($durationMs, 2);

        if ($resultId !== null) {
            $context['resultId'] = $resultId;
        }

        $this->logger->info(
            sprintf('%s handled successfully', $this->shortName($this->commandClass())),
            $context
        );
    }

    /**
     * Log the failure line + carry the resolved error code.
     *
     * @param \Throwable $e          The thrown exception (about to be re-thrown).
     * @param float      $durationMs Wall time elapsed before failure.
     */
    protected function logFailure(\Throwable $e, float $durationMs): void
    {
        $context = $this->logContext();
        $context['exception'] = $e->getMessage();
        $context['exceptionClass'] = $e::class;
        $context['error_code'] = $this->determineErrorCode($e);
        $context['duration_ms'] = round($durationMs, 2);

        $this->logger->error(
            sprintf('Failed to handle %s', $this->shortName($this->commandClass())),
            $context
        );
    }

    /**
     * Default log context — `domain` + `command`. Subclasses extend by
     * overriding to merge command-specific fields.
     *
     * @return array<string, scalar|null> Log fields.
     */
    protected function logContext(): array
    {
        return [
            'domain' => $this->getDomain(),
            'command' => $this->commandClass(),
        ];
    }

    /**
     * Optional hook: if {@see doHandle()} returns an int (aggregate id),
     * include it in the success log line. Subclasses with non-int returns
     * override to return null.
     *
     * @param mixed $result The doHandle return value.
     */
    protected function resultId(mixed $result): ?int
    {
        return is_int($result) ? $result : null;
    }

    /**
     * Resolve a domain-specific error code from the exception.
     *
     * Honours `DomainException::getErrorCode()` and
     * `ValidationException::getErrorCode()` when set; falls back to the
     * subclass-provided default. Critically: NO `str_contains` on
     * exception messages — the prose is presentation, not a contract.
     *
     * @param \Throwable $e The thrown exception.
     * @return int The resolved error code (0 if none + no fallback).
     */
    protected function determineErrorCode(\Throwable $e): int
    {
        if ($e instanceof ValidationException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        if ($e instanceof DomainException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        return $this->defaultErrorCode();
    }

    /**
     * Fallback error code when the exception carries none. Subclasses
     * SHOULD override to return their domain's "save failed" / generic
     * write-error constant.
     */
    protected function defaultErrorCode(): int
    {
        return 0;
    }

    /**
     * Compute the elapsed milliseconds since `$startTime` using the
     * injected clock.
     */
    protected function durationMs(float $startTime): float
    {
        return ($this->clock->now() - $startTime) * 1000;
    }

    /**
     * Last namespace segment of an FQCN — keeps log lines readable
     * (e.g. 'CreateCookieCommand') without losing the FQCN in context.
     */
    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}

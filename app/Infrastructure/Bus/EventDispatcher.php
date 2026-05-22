<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Events\ProcessedEventStoreInterface;
use App\Infrastructure\Logging\CorrelationIdService;
use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Event Dispatcher for domain events.
 *
 * The Event Dispatcher is responsible for:
 * - Collecting domain events from aggregates
 * - Dispatching events to registered listeners
 * - Supporting multiple listeners per event (unlike commands/queries)
 * - Decoupling event producers from consumers
 *
 * Domain Events:
 * Events represent things that HAVE HAPPENED in the domain. They:
 * - Are named in past tense (CookieCreated, CookieDeleted)
 * - Are immutable (you can't change history)
 * - Can have MULTIPLE listeners (unlike commands)
 * - Should not fail (if a listener fails, log via PSR-3 and continue)
 *
 * Error handling (C1):
 * Listener exceptions are caught and logged via the injected PSR-3 logger
 * with full structured context (event class, listener class, correlation id,
 * exception class/message). Other listeners still execute. Calls to
 * error_log() are intentionally avoided because they bypass log aggregation
 * and lose the correlation context that makes failures debuggable in
 * production.
 *
 * @package App\Infrastructure\Bus
 *
 * NOT `final`: PHPUnit's mock generator cannot double a final class and
 * our integration tests subclass to inject failing listeners. Production
 * callers should depend on {@see EventDispatcherInterface} where possible.
 */
class EventDispatcher implements EventDispatcherInterface
{
    /**
     * Map of event class names to arrays of listener callables.
     *
     * @var array<string, array<callable>> Format: [EventClassName => [callable, callable, ...]]
     */
    private array $listeners = [];

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * When true, the first listener exception is rethrown after logging so
     * the surrounding unit-of-work (TransactionMiddleware) can roll back.
     *
     * Default false preserves the original "log-and-continue" contract used
     * outside a transaction (CLI scripts, tests, isolated dispatchers).
     *
     * @var bool
     */
    private bool $rethrowOnListenerFailure = false;

    /**
     * When true, dispatching an event with zero listeners emits a `debug`
     * log line. Off by default — production hot paths shouldn't pay for an
     * always-on diagnostic, and the legitimate no-listener case (events the
     * outbox relay handles asynchronously) would flood the log.
     *
     * Intended for use in dev/test environments where a forgotten
     * subscription is the difference between a wired event and a silent
     * drop. See round-3 audit slice 05/F8.
     *
     * @var bool
     */
    private bool $warnOnNoListeners = false;

    /**
     * Handler-side at-most-once dedup store (round-3 slice 05/F5, epic
     * E12.5). When non-null, the per-listener loop in {@see self::dispatch()}
     * consults this store before invoking each listener and records the
     * `(eventId, listenerClass)` pair after a successful call.
     *
     * Null by default: opt-in until E12 (outbox `event_uuid` UNIQUE)
     * lands. Production callers wire it in via
     * {@see \Config\Services::eventDispatcher()} →
     * {@see self::setProcessedEventStore()}.
     *
     * Only events extending {@see AbstractDomainEvent} carry an `eventId`
     * — the dedup check is therefore conditional on that base class.
     * Events of other shapes bypass the guard silently (the test-only
     * `\stdClass` dispatches in the unit suite never reach the store).
     *
     * @var ProcessedEventStoreInterface|null
     */
    private ?ProcessedEventStoreInterface $processedEventStore = null;

    /**
     * __construct.
     *
     * @param LoggerInterface|null $logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        // Allow zero-arg construction (existing call sites) by falling back
        // to a dedicated dispatcher channel. Production code should inject a
        // shared logger so correlation/redaction processors are consistent.
        $this->logger = $logger ?? LoggerFactory::create('infrastructure.event_dispatcher');
    }

    /**
     * Toggle "rethrow on listener failure" mode and return the previous value.
     *
     * TransactionMiddleware enables this just before invoking the handler so
     * that synchronous-listener failures fail the whole command (rolling back
     * the entity write in the same transaction). The previous value is
     * returned so the caller can restore it in a `finally` block — required
     * for nested command dispatches and for not leaking state into tests.
     *
     * @param bool $rethrow
     * @return bool
     */
    public function setRethrowOnListenerFailure(bool $rethrow): bool
    {
        $previous = $this->rethrowOnListenerFailure;
        $this->rethrowOnListenerFailure = $rethrow;
        return $previous;
    }

    /**
     * Toggle "log a debug line on zero-listener dispatches" mode and
     * return the previous value.
     *
     * Off by default. Dev/test environments can flip it on to surface
     * events that nobody subscribed to (typically the symptom of a
     * forgotten {@see \App\Infrastructure\ServiceProvider\DomainServiceProvider::registerEvents()}
     * entry on a new event). The previous value is returned so callers
     * can restore state in a `finally` block.
     *
     * @param bool $warn
     * @return bool
     */
    public function setWarnOnNoListeners(bool $warn): bool
    {
        $previous = $this->warnOnNoListeners;
        $this->warnOnNoListeners = $warn;
        return $previous;
    }

    /**
     * Install (or remove) the handler-side at-most-once dedup store.
     *
     * Pass `null` to disable the guard entirely (the dispatcher behaves
     * the way it did before epic E12.5). Pass a
     * {@see ProcessedEventStoreInterface} instance to enable the
     * `isProcessed → invoke → markProcessed` protocol around every
     * listener call.
     *
     * # Trade-off
     *
     * Each enabled dispatch adds one SELECT and (on success) one
     * INSERT-IGNORE per listener. That's two extra round-trips per
     * event×listener pair — meaningful on a tight event loop but
     * negligible against the network/IO cost of any real side-effect
     * handler (webhooks, email, payments). Until E12 (outbox-side
     * UUID UNIQUE) lands the guard is opt-in so the cost is paid only
     * where it earns its keep.
     *
     * Returns the previous store so callers can restore it in a
     * `finally` block — the same pattern as the other state-mutating
     * setters on this class.
     *
     * @param ProcessedEventStoreInterface|null $store
     * @return ProcessedEventStoreInterface|null Previous store (null when unset).
     */
    public function setProcessedEventStore(?ProcessedEventStoreInterface $store): ?ProcessedEventStoreInterface
    {
        $previous = $this->processedEventStore;
        $this->processedEventStore = $store;
        return $previous;
    }

    /**
     * Register a listener for an event.
     *
     * Multiple listeners can be registered for the same event.
     *
     * @param string   $eventClass Fully qualified event class name
     * @param callable $listener   The listener callable
     * @return void
     */
    public function subscribe(string $eventClass, callable $listener): void
    {
        if (!isset($this->listeners[$eventClass])) {
            $this->listeners[$eventClass] = [];
        }

        $this->listeners[$eventClass][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * Listeners are called in the order they were registered.
     * If a listener throws, the exception is logged via the structured
     * logger and other listeners still execute.
     *
     * @param object $event The event to dispatch
     * @return void
     */
    public function dispatch(object $event): void
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            if ($this->warnOnNoListeners) {
                // `debug` (not `warning`) — production may legitimately
                // dispatch an event whose subscribers live on the async
                // outbox side. The hook is for dev/test diagnostics, not
                // a production alarm.
                $this->logger->debug('Event dispatched with no listeners', [
                    'domain' => 'Infrastructure',
                    'component' => 'EventDispatcher',
                    'event_class' => $eventClass,
                    'correlation_id' => CorrelationIdService::get(),
                ]);
            }
            return;
        }

        // Handler-side at-most-once (epic E12.5): when the store is bound
        // AND the event carries an `eventId` (i.e. extends
        // AbstractDomainEvent), each listener sits inside a
        // isProcessed → invoke → markProcessed bracket. `markProcessed`
        // runs only on success — a thrown listener leaves the pair
        // unmarked so the next retry will invoke it again.
        $eventId = $this->processedEventStore !== null && $event instanceof AbstractDomainEvent
            ? $event->eventId
            : null;

        foreach ($this->listeners[$eventClass] as $listener) {
            $listenerClass = $this->describeListener($listener);

            if ($eventId !== null && $this->processedEventStore?->isProcessed($eventId, $listenerClass) === true) {
                // Already processed in a previous attempt — skip silently.
                // The retry contract is "at-most-once on success", so the
                // dispatcher does NOT log every skip; doing so would flood
                // the log on every relay replay.
                continue;
            }

            try {
                $listener($event);
            } catch (\Throwable $e) {
                $this->logger->error('Event listener failed', [
                    'domain' => 'Infrastructure',
                    'component' => 'EventDispatcher',
                    'event_class' => $eventClass,
                    'listener' => $listenerClass,
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                    'correlation_id' => CorrelationIdService::get(),
                ]);

                if ($this->rethrowOnListenerFailure) {
                    // Stop fanning-out: inside a transactional unit of work
                    // we want the first failure to propagate so the whole
                    // command rolls back. The remaining listeners can run
                    // again when the command is retried.
                    throw $e;
                }

                // Failure path: do NOT call markProcessed. The retry must
                // be able to invoke this listener again next time.
                continue;
            }

            if ($eventId === null) {
                // Store not bound or event lacks an `eventId` (test-only
                // dispatches, legacy event shapes) — nothing to record.
                continue;
            }

            $this->processedEventStore?->markProcessed($eventId, $listenerClass);
        }
    }

    /**
     * Check if there are listeners for an event.
     *
     * @param string $eventClass The event class name
     * @return bool True if listeners are registered
     */
    public function hasListeners(string $eventClass): bool
    {
        return isset($this->listeners[$eventClass]) && count($this->listeners[$eventClass]) > 0;
    }

    /**
     * Get the number of listeners for an event.
     *
     * @param string $eventClass The event class name
     * @return int Number of registered listeners
     */
    public function getListenerCount(string $eventClass): int
    {
        return isset($this->listeners[$eventClass]) ? count($this->listeners[$eventClass]) : 0;
    }

    /**
     * describeListener.
     *
     * @param callable $listener
     * @return string
     */
    private function describeListener(callable $listener): string
    {
        if (is_object($listener) && !($listener instanceof \Closure)) {
            return $listener::class;
        }

        if (is_array($listener) && count($listener) === 2) {
            $obj = $listener[0];
            $method = $listener[1];
            $class = is_object($obj) ? $obj::class : (string) $obj;
            return $class . '::' . (string) $method;
        }

        if (is_string($listener)) {
            return $listener;
        }

        return 'Closure';
    }
}

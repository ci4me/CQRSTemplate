<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

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
 */
class EventDispatcher
{
    /**
     * Map of event class names to arrays of listener callables.
     *
     * @var array<string, array<callable>> Format: [EventClassName => [callable, callable, ...]]
     */
    private array $listeners = [];

    private LoggerInterface $logger;

    /**
     * When true, the first listener exception is rethrown after logging so
     * the surrounding unit-of-work (TransactionMiddleware) can roll back.
     *
     * Default false preserves the original "log-and-continue" contract used
     * outside a transaction (CLI scripts, tests, isolated dispatchers).
     */
    private bool $rethrowOnListenerFailure = false;

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
     */
    public function setRethrowOnListenerFailure(bool $rethrow): bool
    {
        $previous = $this->rethrowOnListenerFailure;
        $this->rethrowOnListenerFailure = $rethrow;
        return $previous;
    }

    /**
     * Register a listener for an event.
     *
     * Multiple listeners can be registered for the same event.
     *
     * @param string $eventClass Fully qualified event class name
     * @param callable $listener The listener callable
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
     */
    public function dispatch(object $event): void
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        foreach ($this->listeners[$eventClass] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                $this->logger->error('Event listener failed', [
                    'domain' => 'Infrastructure',
                    'component' => 'EventDispatcher',
                    'event_class' => $eventClass,
                    'listener' => $this->describeListener($listener),
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
            }
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus;

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
 * - Should not fail (if a listener fails, catch and log)
 *
 * Why Domain Events:
 * - Decouple bounded contexts (e.g., Order created -> send email)
 * - Audit trail (know what happened and when)
 * - Event sourcing foundation (if needed later)
 * - Side effects without coupling (logging, notifications, etc.)
 *
 * Usage Example:
 * ```php
 * $event = new CookieCreatedEvent($cookieId, $cookieName);
 * $eventDispatcher->dispatch($event);
 * ```
 *
 * Note: In a simple implementation like this, events are dispatched
 * synchronously. For async processing, consider a queue system.
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
     * If a listener throws an exception, it's caught and logged,
     * but other listeners still execute.
     *
     * @param object $event The event to dispatch
     */
    public function dispatch(object $event): void
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            // No listeners registered for this event - that's okay
            return;
        }

        foreach ($this->listeners[$eventClass] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $e) {
                // Log the error but don't stop other listeners
                // In production, this should use a proper logger
                error_log(
                    sprintf(
                        'Event listener failed for %s: %s',
                        $eventClass,
                        $e->getMessage()
                    )
                );
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
}

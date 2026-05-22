<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

/**
 * Handler-side at-most-once idempotency port (round-3 slice 05/F5 / epic E12.5).
 *
 * Even with the outbox-layer `event_uuid` UNIQUE constraint introduced in
 * epic E12, retries can still double-process at the handler layer: a worker
 * that successfully invoked a listener but died before ACKing the outbox row
 * will re-deliver the same event on restart. Without a handler-side dedup,
 * side-effect listeners (webhook senders, email senders, accounting
 * postings, anything externally observable) will fire twice.
 *
 * This port describes a small key-value store keyed on the pair
 * `(eventId, listenerClass)`. The dispatcher consults the store before
 * invoking each listener and skips the call when the pair is already
 * recorded; after a successful listener call it records the pair so the
 * next retry is a no-op.
 *
 * # Why both keys are required
 *
 * - `eventId` alone is not enough: the same event can have multiple
 *   listeners and EACH listener has its own at-most-once channel — Listener
 *   A might have completed while Listener B threw, so the retry must call
 *   B but skip A.
 * - `listenerClass` alone is not enough: the same listener legitimately
 *   processes many different events.
 *
 * # When to use this port
 *
 * Side-effect handlers MUST consume an at-most-once channel like this one.
 * "Side-effect handler" means any listener whose work is not naturally
 * idempotent at its target — webhook POSTs, outbound email, payment API
 * calls, push-notification dispatch, audit-log row writes. Listeners that
 * are accidentally idempotent today (a logger; a read-model upsert keyed
 * on the aggregate id) do not strictly require the guard but lose nothing
 * by adopting it.
 *
 * # Ordering contract
 *
 * The dispatcher's protocol is:
 *
 *   1. `if ($store->isProcessed($eventId, $listenerClass)) { continue; }`
 *   2. invoke the listener
 *   3. on success: `$store->markProcessed($eventId, $listenerClass)`
 *
 * A listener throwing means `markProcessed()` is NOT called — so the
 * retry will succeed next time. This is "at-most-once on success, at-least
 * -once on failure", which matches every real-world retry contract.
 *
 * # Pairs with E12 for end-to-end at-most-once
 *
 * Combine with the outbox `event_uuid` UNIQUE constraint (epic E12) and
 * the overall system becomes end-to-end at-most-once: E12 stops the relay
 * from re-PUBLISHING the same event; E12.5 (this port) stops a re-published
 * event from being re-PROCESSED by a listener that already finished.
 *
 * @package App\Domain\Shared\Events
 */
interface ProcessedEventStoreInterface
{
    /**
     * Has the `(eventId, listenerClass)` pair already been recorded as
     * processed?
     *
     * @param string $eventId       UUIDv7 from {@see AbstractDomainEvent::$eventId}.
     * @param string $listenerClass FQCN of the listener (or a stable
     *                              describe-string for closures/array
     *                              callables — see
     *                              {@see \App\Infrastructure\Bus\EventDispatcher}).
     * @return bool True iff the pair was previously marked via {@see self::markProcessed()}.
     */
    public function isProcessed(string $eventId, string $listenerClass): bool;

    /**
     * Record `(eventId, listenerClass)` as processed.
     *
     * Idempotent by contract: calling twice MUST NOT raise — the adapter
     * MUST swallow the duplicate-key race that arises when two workers
     * dispatch the same event concurrently and both reach this method
     * with the same key.
     */
    public function markProcessed(string $eventId, string $listenerClass): void;
}

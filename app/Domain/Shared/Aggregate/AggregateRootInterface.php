<?php

declare(strict_types=1);

namespace App\Domain\Shared\Aggregate;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Contract every aggregate root in the domain layer satisfies.
 *
 * An aggregate root accumulates domain events during its lifecycle and
 * exposes a "pull-then-clear" handle so the repository (or an explicit
 * caller) can drain them after a successful persist. This interface
 * documents that contract so static analysis, repositories and the event
 * bus can rely on it instead of duck-typing on the {@see \App\Domain\Shared\AggregateRoot}
 * trait directly.
 *
 * Implementations:
 *  - {@see \App\Domain\Cookie\Entities\Cookie}
 *
 * Why an interface and not just the trait?
 *  - PHPStan can constrain repository / dispatcher signatures to this
 *    interface rather than the concrete entity, keeping the persistence
 *    surface narrow.
 *  - Domains adding lifecycle methods (E07) inherit a clear obligation
 *    rather than reverse-engineering the trait.
 *  - When PHP 8.4 lands (E16), the `getId(): ?int` accessor will be
 *    expressible as `private(set)` on the property and the interface stays
 *    the only contract callers depend on.
 *
 * The interface intentionally does NOT extend {@see DomainEventInterface};
 * aggregates produce events but are not events themselves.
 *
 * @package App\Domain\Shared\Aggregate
 */
interface AggregateRootInterface
{
    /**
     * Return the queued events and clear the buffer.
     *
     * Calling `pullEvents()` twice yields the same set the first time and
     * an empty list the second — drain is single-shot by design so the
     * repository cannot double-publish during a retry.
     *
     * @return list<DomainEventInterface>
     */
    public function pullEvents(): array;

    /**
     * Inspect queued events without clearing the buffer. Test-only helper
     * (the trait raises this from `peekEvents()`; production callers should
     * use `pullEvents()` so the contract stays single-shot).
     *
     * @return list<DomainEventInterface>
     */
    public function peekEvents(): array;

    /**
     * Whether the aggregate has any events queued. Cheap predicate used by
     * the repository to short-circuit dispatcher calls when nothing
     * changed.
     */
    public function hasPendingEvents(): bool;

    /**
     * The aggregate's database identity, or `null` if it has not yet been
     * persisted. Tightened from `getId(): int|null` because the unpersisted
     * branch is part of the aggregate's lifecycle (pre-save factories
     * return entities with `id === null`).
     */
    public function getId(): ?int;
}

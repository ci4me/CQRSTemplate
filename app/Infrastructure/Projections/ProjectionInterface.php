<?php

declare(strict_types=1);

namespace App\Infrastructure\Projections;

/**
 * Contract every read-model projection implements (D15).
 *
 * Each projection owns one read table. The class:
 *  - declares its `name()` so the rebuild command can target it from CLI;
 *  - declares which event classes it cares about via `subscribesTo()` and
 *    handles each one in `apply()`;
 *  - exposes `truncate()` and `rebuildFromSource(callable)` for the
 *    rebuild flow.
 *
 * The runner ({@see ProjectionRegistry::register()}) wires `apply()` calls
 * to the EventDispatcher so projections stay current as events flow.
 */
interface ProjectionInterface
{
    /**
     * name.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Fully qualified event class names this projection handles.
     *
     * @return list<class-string>
     */
    public function subscribesTo(): array;

    /**
     * Apply an event to the projection. Implementations must be idempotent
     * — the same event may be replayed during a rebuild.
     *
     * @param object $event
     * @return void
     */
    public function apply(object $event): void;

    /**
     * Drop every row in this projection's table. Called by
     * `projections:rebuild` before replaying.
     *
     * @return void
     */
    public function truncate(): void;

    /**
     * Rebuild the projection from the current state of the source
     * aggregates (when event history isn't available).
     *
     * @param callable|null $progressCallback * @param callable(self): void $progressCallback optional progress hook
     * @return void
     */
    public function rebuildFromSource(?callable $progressCallback = null): void;
}

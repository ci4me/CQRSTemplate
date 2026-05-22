<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Shared\Events\CookieChangeSet;

/**
 * Typed snapshot of a Cookie aggregate's state at a moment in time.
 *
 * Wraps a {@see CookieChangeSet} to carry the aggregate's whitelisted
 * public state for events (CookieDeleted, CookieUpdated.before/after).
 * Compared to passing a raw `CookieChangeSet`, the snapshot adds a
 * domain-meaningful name that lets call sites read like prose:
 *
 *     $event = new CookieDeletedEvent(..., snapshot: $cookie->snapshot());
 *
 * Closes round-3 audit slice 01/F11 — the original `snapshot()` returned
 * a loose array<string, scalar|null> whose key set varied with the
 * cloner's mood. Encoding the snapshot as a VO over the change-set VO
 * gives us:
 *   - a stable shape (validated by `CookieChangeSet::ALLOWED_KEYS`);
 *   - a single place to bolt on future operations (`diff()`, `equals()`,
 *     `withoutSensitive()`) without scattering free-form arrays around;
 *   - a typed parameter for handlers / projections that previously had
 *     to accept `array<string, scalar|null>` and re-validate by feel.
 *
 * The class is `final readonly` because a snapshot is a fact, not a
 * mutable accumulator.
 *
 * @package App\Domain\Cookie\ValueObjects
 */
final readonly class CookieSnapshot
{
    /**
     * @param CookieChangeSet $changeSet Whitelisted state map.
     */
    public function __construct(
        public CookieChangeSet $changeSet,
    ) {
    }

    /**
     * Convenience factory mirroring the rest of the Cookie VO style.
     *
     * @param array<string, scalar|null> $changes Whitelisted key/value pairs.
     * @throws \InvalidArgumentException When the change set rejects a key.
     */
    public static function fromArray(array $changes): self
    {
        return new self(CookieChangeSet::fromArray($changes));
    }

    /**
     * Expose the underlying change set for callers that expect the
     * typed VO contract (e.g. CookieDeletedEvent::$snapshot).
     */
    public function toChangeSet(): CookieChangeSet
    {
        return $this->changeSet;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->changeSet->toArray();
    }

    /**
     * Whether the snapshot carries no fields. Mirrors
     * {@see CookieChangeSet::isEmpty()}.
     */
    public function isEmpty(): bool
    {
        return $this->changeSet->isEmpty();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Shared\Events;

/**
 * Typed snapshot value object used by {@see \App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent}
 * and {@see \App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent} to
 * carry the before/after state diff in a controlled shape.
 *
 * Replaces the loose `array<string, scalar|null>` snapshots the round-3
 * audit (slice 05/F4) flagged as a latent PII-leakage risk: an unbounded
 * "dump anything here" array invites cloners to a domain with personal
 * data (Customer, Employee) to pour an entire entity into the outbox row
 * — which is then archived in `event_outbox` and downstream log
 * aggregators in perpetuity.
 *
 * The whitelist below is the *public state* of the Cookie aggregate as it
 * appears on the wire. Adding a new field is a deliberate change: the
 * cloner must extend the whitelist (or fork this VO for their domain),
 * which gives a reviewer a chance to ask "should this be in the outbox?".
 *
 * Why a value object (not a plain array):
 * - The constructor's `\InvalidArgumentException` is the hard gate that
 *   makes the whitelist enforcement non-skippable.
 * - `final readonly` keeps the snapshot immutable once raised.
 *
 * @package App\Domain\Shared\Events
 */
final readonly class CookieChangeSet
{
    /**
     * Whitelisted snapshot keys. Anything outside this set is rejected at
     * construction time. Extend deliberately; never silently widen.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'id',
        'name',
        'description',
        'price_minor',
        'price_currency',
        'stock',
        'is_active',
        'version',
        'deleted_at',
    ];

    /**
     * @var array<string, scalar|null>
     */
    private array $changes;

    /**
     * @param array<string, scalar|null> $changes Whitelisted key/value pairs (see {@see self::ALLOWED_KEYS}).
     * @throws \InvalidArgumentException When an unknown key is supplied.
     */
    public function __construct(array $changes)
    {
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'CookieChangeSet rejects unknown key "%s"; allowed: %s',
                        $key,
                        implode(', ', self::ALLOWED_KEYS)
                    )
                );
            }
        }

        $this->changes = $changes;
    }

    /**
     * Static factory mirroring the rest of the codebase's VO style.
     *
     * @param array<string, scalar|null> $changes
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $changes): self
    {
        return new self($changes);
    }

    /**
     * Empty change set, useful for constructor defaults on events that may
     * not always carry a snapshot (e.g. unit tests).
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return $this->changes;
    }

    /**
     * isEmpty.
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}

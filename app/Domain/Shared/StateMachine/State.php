<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

/**
 * Marker interface for the constants/enum values that participate in a
 * state machine (D5).
 *
 * The scaffold is deliberately interface-only so each domain can use either:
 *  - a backed string enum (recommended) — implement this interface
 *    and rely on the enum's `value` for serialisation
 *  - a plain class with constants — implement {@see self::stateName()}
 *
 * The state machine itself doesn't care which representation a domain
 * picks; it only requires `stateName()` to return a stable string.
 */
interface State
{
    public function stateName(): string;
}

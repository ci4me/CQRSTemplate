<?php

declare(strict_types=1);

namespace App\Domain\Shared\Aggregate;

/**
 * Unforgeable "permission token" required by aggregate hydration paths.
 *
 * PHP has no package-private visibility, so methods that ought to be
 * reachable only by the repository (`assignId`, `bumpVersion`) cannot be
 * `protected` (the entity is `final`) nor truly hidden by an `@internal`
 * docblock (it is a hint, not a check). This class is the long-term
 * replacement for that discipline: production callers wishing to invoke
 * such methods must first obtain an `AggregateHydrator` instance, and the
 * only way to obtain one is `AggregateHydrator::key()`.
 *
 * The protection comes in two layers:
 *
 *  1. By convention now — the docblock and the `@internal` flag on
 *     `key()` make it obvious the factory is part of the hydration
 *     contract, not a general utility.
 *  2. By PHPStan custom rule later (epic E05.5) — a rule restricts which
 *     namespaces may call `AggregateHydrator::key()`. After that lands,
 *     a controller or command handler that tries to forge a key fails
 *     the static analysis gate.
 *
 * In PHP 8.4 (epic E16) the same intent is expressible as
 * `private(set)` on the underlying properties, which would let the
 * entity drop the key parameter entirely. We keep the key approach
 * around until the project's minimum PHP version moves.
 *
 * The class is `final` (not `final readonly`) because it is a
 * permission token, not a value object — equality / property
 * comparison is not part of its public contract.
 *
 * @package App\Domain\Shared\Aggregate
 * @internal Only repository hydration paths should construct this.
 */
final class AggregateHydrator
{
    private function __construct()
    {
    }

    /**
     * Mint a fresh hydrator key.
     *
     * Callers must already be inside a hydration path (a repository
     * `save` / `reconstitute` / `findById` flow). A future PHPStan rule
     * (E05.5) will fail the build if a call to this factory appears
     * outside that allow-list.
     *
     * @internal
     */
    public static function key(): self
    {
        return new self();
    }
}

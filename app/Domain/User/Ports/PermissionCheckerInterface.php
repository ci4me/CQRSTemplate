<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Permission;

/**
 * Domain port for permission-checking decisions.
 *
 * Domain handlers consult this port to ask "may this actor perform this
 * permission?" without coupling to the concrete Infrastructure permission
 * service (which knows about RBAC tables, the legacy `users.role` enum, and
 * the database).
 *
 * The port intentionally exposes only the two yes/no methods handlers
 * actually call. Anything richer (batch checks, audit context) would go on
 * a separate, opt-in port.
 *
 * @package App\Domain\User\Ports
 */
interface PermissionCheckerInterface
{
    /**
     * Does $actor hold $permission?
     *
     * Implementations are fail-closed: returning false on lookup error keeps
     * authz decisions safe by default.
     *
     * @param Actor      $actor      The subject (resolved by ActorResolver).
     * @param Permission $permission The permission being checked.
     * @return bool True iff the actor is allowed.
     */
    public function allows(Actor $actor, Permission $permission): bool;

    /**
     * Negation helper — exists so handlers can read more naturally
     * (`if ($this->permissions->denies(...))`) without negating manually.
     *
     * @param Actor      $actor      The subject (resolved by ActorResolver).
     * @param Permission $permission The permission being checked.
     * @return bool True iff the actor is NOT allowed.
     */
    public function denies(Actor $actor, Permission $permission): bool;
}

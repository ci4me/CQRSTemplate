<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Permission;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Resolves whether an actor holds a given permission.
 *
 * Lookup: user → roles → role_permissions → permissions.name.
 *
 * Backwards-compatibility shim: the existing User entity carries a `role`
 * enum column (admin|customer). Until the data migration assigns every
 * existing user to RBAC roles, this service also honors the legacy column —
 * an `admin` role string grants every permission. The shim is intentionally
 * loud: the comment block flags it as transitional so it gets removed.
 *
 * Results are NOT cached per-request to avoid stale denials when role grants
 * change mid-request. Callers that fan out many checks should batch via the
 * future `allowed(list<Permission>)` API (TODO).
 *
 * NOT `final`: PHPUnit's mock generator cannot double a final class and
 * several handlers depend on this service. Extending in production code
 * is still discouraged — prefer composition.
 */
readonly class PermissionService
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * allows.
     *
     * @param Actor      $actor
     * @param Permission $permission
     * @return bool
     */
    public function allows(Actor $actor, Permission $permission): bool
    {
        // SECURITY: do NOT auto-grant the system actor. ActorResolver is
        // fail-closed for HTTP requests, but a buggy code path that
        // resolves an actor to Actor::system() (e.g. CLI-style invocation
        // inside an HTTP context) must not silently bypass authz. The
        // system actor is granted access only by explicit command-handler
        // bypass (e.g. migrations) — not here.
        if ($actor->isSystem()) {
            return false;
        }

        if ($this->legacyAdminCheck($actor)) {
            return true;
        }

        return $this->rbacCheck($actor, $permission);
    }

    /**
     * denies.
     *
     * @param Actor      $actor
     * @param Permission $permission
     * @return bool
     */
    public function denies(Actor $actor, Permission $permission): bool
    {
        return !$this->allows($actor, $permission);
    }

    /**
     * TRANSITIONAL: until users are migrated to user_roles, the legacy
     * users.role enum still controls admin access. Remove this method
     * once every user has at least one row in user_roles.
     *
     * @param Actor $actor
     * @return bool
     */
    private function legacyAdminCheck(Actor $actor): bool
    {
        try {
            $db = $this->db ?? Database::connect();
            $result = $db->table('users')
                ->select('role')
                ->where('id', $actor->id)
                ->get();

            if ($result === false) {
                return false;
            }

            $row = $result->getRowArray();

            return is_array($row) && ($row['role'] ?? null) === 'admin';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * rbacCheck.
     *
     * @param Actor      $actor
     * @param Permission $permission
     * @return bool
     */
    private function rbacCheck(Actor $actor, Permission $permission): bool
    {
        try {
            $db = $this->db ?? Database::connect();

            $result = $db->table('user_roles ur')
                ->select('p.name')
                ->join('role_permissions rp', 'rp.role_id = ur.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->where('ur.user_id', $actor->id)
                ->where('p.name', $permission->name)
                ->countAllResults();

            return $result > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}

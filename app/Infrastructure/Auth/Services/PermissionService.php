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
 */
final readonly class PermissionService
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    public function allows(Actor $actor, Permission $permission): bool
    {
        if ($actor->isSystem()) {
            // System actor (cron, migrations) bypasses checks by design.
            return true;
        }

        if ($this->legacyAdminCheck($actor)) {
            return true;
        }

        return $this->rbacCheck($actor, $permission);
    }

    public function denies(Actor $actor, Permission $permission): bool
    {
        return !$this->allows($actor, $permission);
    }

    /**
     * TRANSITIONAL: until users are migrated to user_roles, the legacy
     * users.role enum still controls admin access. Remove this method
     * once every user has at least one row in user_roles.
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

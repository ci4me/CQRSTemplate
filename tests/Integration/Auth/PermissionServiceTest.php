<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Shared\ValueObjects\Permission;
use App\Infrastructure\Auth\Services\PermissionService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

final class PermissionServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected bool $migrate = true;
    protected bool $refresh = true;
    protected ?string $namespace = null;

    public function test_system_actor_is_always_allowed(): void
    {
        $service = new PermissionService();

        $this->assertTrue($service->allows(Actor::system(), Permission::fromString('cookies.create')));
    }

    public function test_user_with_admin_role_is_allowed_via_legacy_shim(): void
    {
        $userId = $this->insertUser('admin');
        $service = new PermissionService();

        $this->assertTrue($service->allows(Actor::user($userId), Permission::fromString('cookies.create')));
        $this->assertTrue($service->allows(Actor::user($userId), Permission::fromString('invoices.post')));
    }

    public function test_customer_without_rbac_grant_is_denied(): void
    {
        $userId = $this->insertUser('customer');
        $service = new PermissionService();

        $this->assertFalse($service->allows(Actor::user($userId), Permission::fromString('cookies.create')));
        $this->assertTrue($service->denies(Actor::user($userId), Permission::fromString('cookies.create')));
    }

    public function test_customer_with_rbac_grant_is_allowed(): void
    {
        $userId = $this->insertUser('customer');
        $this->grantPermission($userId, 'cookies.view');

        $service = new PermissionService();

        $this->assertTrue($service->allows(Actor::user($userId), Permission::fromString('cookies.view')));
        $this->assertFalse($service->allows(Actor::user($userId), Permission::fromString('cookies.delete')));
    }

    private function insertUser(string $role): int
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');
        $db->table('users')->insert([
            'name' => 'Perm Test',
            'email' => 'perm-test-' . uniqid('', true) . '@example.test',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$xx$' . str_repeat('a', 43),
            'role' => $role,
            'status' => 'active',
            'failed_login_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $db->insertID();
    }

    private function grantPermission(int $userId, string $permission): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $permId = (int) $db->table('permissions')->insert([
            'name' => $permission,
            'description' => 'test',
            'created_at' => $now,
        ], true);

        $roleId = (int) $db->table('roles')->insert([
            'slug' => 'test-role-' . uniqid('', true),
            'name' => 'Test Role',
            'created_at' => $now,
        ], true);

        $db->table('role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $permId,
        ]);

        $db->table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId,
            'granted_at' => $now,
        ]);
    }
}

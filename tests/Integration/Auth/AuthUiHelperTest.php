<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use Config\Database;
use Tests\Support\IntegrationTestCase;

final class AuthUiHelperTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('auth_ui');
    }

    public function test_can_returns_false_for_invalid_permission_string(): void
    {
        $this->assertFalse(can('not-a-valid-shape'));
        $this->assertFalse(can(''));
    }

    public function test_can_returns_false_when_no_actor_lacks_permission(): void
    {
        // SECURITY: with no session, the resolved actor is the system actor.
        // PermissionService now denies the system actor by default (the
        // bypass for legitimate system-side operations is opt-in at the
        // call site, not implicit in authz), so can() returns false.
        $this->assertFalse(can('cookies.update'));
    }

    public function test_can_returns_true_for_admin_user_via_legacy_shim(): void
    {
        $userId = $this->insertAdminUser();
        $this->mockSession();
        session()->set('user_id', $userId);

        // Even an unknown permission returns true for admin (legacy shim).
        $this->assertTrue(can('orders.approve'));
    }

    public function test_can_returns_false_for_non_admin_without_explicit_grant(): void
    {
        $userId = $this->insertUser('customer');
        $this->mockSession();
        session()->set('user_id', $userId);

        $this->assertFalse(can('orders.approve'));
    }

    public function test_can_returns_true_for_user_with_rbac_grant(): void
    {
        $userId = $this->insertUser('customer');
        $this->grantPermission($userId, 'reports.view');

        $this->mockSession();
        session()->set('user_id', $userId);

        $this->assertTrue(can('reports.view'));
        $this->assertFalse(can('reports.export'));
    }

    public function test_cannot_inverts_can(): void
    {
        $userId = $this->insertUser('customer');
        $this->mockSession();
        session()->set('user_id', $userId);

        $this->assertTrue(cannot('orders.approve'));
    }

    public function test_any_of_returns_true_when_any_match(): void
    {
        $userId = $this->insertUser('customer');
        $this->grantPermission($userId, 'reports.view');
        $this->mockSession();
        session()->set('user_id', $userId);

        $this->assertTrue(any_of('orders.approve', 'reports.view', 'invoices.post'));
        $this->assertFalse(any_of('orders.approve', 'invoices.post'));
    }

    public function test_all_of_requires_every_permission(): void
    {
        $userId = $this->insertUser('customer');
        $this->grantPermission($userId, 'reports.view');
        $this->mockSession();
        session()->set('user_id', $userId);

        $this->assertFalse(all_of('reports.view', 'orders.approve'));
        $this->assertTrue(all_of('reports.view'));
    }

    private function insertUser(string $role): int
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('users')->insert([
            'name' => 'UI Helper Test',
            'email' => 'ui-' . uniqid('', true) . '@example.test',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$xx$' . str_repeat('a', 43),
            'role' => $role,
            'status' => 'active',
            'failed_login_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) Database::connect()->insertID();
    }

    private function insertAdminUser(): int
    {
        return $this->insertUser('admin');
    }

    private function grantPermission(int $userId, string $permission): void
    {
        $db = Database::connect();
        $now = date('Y-m-d H:i:s');

        $db->table('permissions')->insert([
            'name' => $permission,
            'description' => 'test',
            'created_at' => $now,
        ]);
        $permId = (int) $db->insertID();

        $db->table('roles')->insert([
            'slug' => 'test-' . uniqid('', true),
            'name' => 'Test',
            'created_at' => $now,
        ]);
        $roleId = (int) $db->insertID();

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

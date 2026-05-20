<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * RBAC schema for ERP permissions (D3).
 *
 * Tables:
 *   permissions     — catalog of known permission strings (e.g. "cookies.create")
 *   roles           — named roles ("admin", "warehouse", "accounting")
 *   role_permissions — many-to-many (role grants permission)
 *   user_roles      — many-to-many (user has role)
 *
 * Permission strings use dotted notation `{module}.{action}` so they read
 * naturally in code (e.g. `Gate::allows('cookies.update')`).
 *
 * Why a real RBAC schema instead of the existing role enum on `users`:
 * - String-on-user (admin|customer) cannot express granular grants.
 * - ERPs need per-module permissions (cookies.create, invoices.post,
 *   reports.export) and the ability to compose roles.
 * - Per-record / per-company scoping can layer on top later via additional
 *   joins (role_permissions.conditions JSON, scoped policies, etc.).
 */
class CreatePermissionsSchema extends Migration
{
    public function up(): void
    {
        $this->createPermissionsTable();
        $this->createRolesTable();
        $this->createRolePermissionsTable();
        $this->createUserRolesTable();
    }

    public function down(): void
    {
        $this->forge->dropTable('user_roles', true);
        $this->forge->dropTable('role_permissions', true);
        $this->forge->dropTable('roles', true);
        $this->forge->dropTable('permissions', true);
    }

    private function createPermissionsTable(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'description' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('name');
        $this->forge->createTable('permissions', true);
    }

    private function createRolesTable(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'slug' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => false,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'description' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('slug');
        $this->forge->createTable('roles', true);
    }

    private function createRolePermissionsTable(): void
    {
        $this->forge->addField([
            'role_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'permission_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey(['role_id', 'permission_id']);
        $this->forge->addKey('permission_id');
        // Referential integrity: deleting a role MUST also remove its
        // grants; same for a permission. Without these FKs a deleted
        // role leaves orphan rows that grant nothing-against-nothing.
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('permission_id', 'permissions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('role_permissions', true);
    }

    private function createUserRolesTable(): void
    {
        $this->forge->addField([
            'user_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'role_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'granted_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey(['user_id', 'role_id']);
        $this->forge->addKey('role_id');
        // Same rationale as role_permissions: drop the join row when
        // either side is deleted.
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('role_id', 'roles', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('user_roles', true);
    }
}

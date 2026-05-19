<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to create the cookies table.
 *
 * This table doubles as the canonical ERP entity template, so it carries the
 * ERP-baseline columns every operational entity should have:
 *
 * - tenant_id   : multi-company / multi-tenant scope (B11). Nullable while
 *                 the template is single-tenant; required by the time a real
 *                 tenant resolver is wired in.
 * - version     : optimistic locking token (B9). Incremented on every save;
 *                 repositories MUST compare and increment to avoid lost writes.
 * - created_by  : audit trail (B10) — actor that created the row.
 * - updated_by  : audit trail (B10) — actor that performed the last update.
 * - deleted_by  : audit trail (B10) — actor that soft-deleted the row.
 *
 * Uniqueness:
 * - Composite UNIQUE(tenant_id, name, deleted_at) replaces the global UNIQUE(name)
 *   so that soft-deleted rows do not block creation of a new row with the same
 *   name (B16), restoration is possible (B17), and uniqueness is scoped per tenant.
 * - The `name` column collation is pinned to utf8mb4_unicode_ci so case-
 *   insensitive uniqueness does not depend on the connection's default (B7).
 *
 * Indexes:
 * - PRIMARY: id
 * - UNIQUE: (tenant_id, name, deleted_at)
 * - INDEX: is_active (filter active cookies)
 * - INDEX: deleted_at (soft delete queries)
 * - INDEX: tenant_id (per-tenant scans)
 *
 * @package App\Database\Migrations
 */
class CreateCookiesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
                // B7: pin case-insensitive collation so uniqueness does not
                // depend on the connection / database default collation.
                'collation' => 'utf8mb4_unicode_ci',
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'price' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false,
            ],
            'stock' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => 1,
            ],
            'version' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'created_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'updated_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'deleted_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');

        // B16: composite uniqueness on (tenant_id, name, deleted_at) so that
        // soft-deleted rows do not block re-creation and restoration is possible.
        $this->forge->addUniqueKey(['tenant_id', 'name', 'deleted_at']);

        $this->forge->addKey('is_active');
        $this->forge->addKey('deleted_at');
        $this->forge->addKey('tenant_id');

        $this->forge->createTable('cookies', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('cookies', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Drop the cookie_read_model table (Phase 2 — Cookie data simplification).
 *
 * Phase 2 of the stabilization refactor collapses the Cookie read model into
 * the canonical `cookies` table. The CQRS code-level separation is preserved
 * (CookieQueryRepository is still distinct from CookieRepository), but both
 * sides now query the same physical table — appropriate for a single-aggregate
 * template that does not need a denormalised projection.
 *
 * `up()`   drops `cookie_read_model`.
 * `down()` re-creates it with the original schema so the table can be restored
 * if a future domain re-introduces a true denormalised projection. The
 * preserved reference projection lives at
 * `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example`.
 */
class DropCookieReadModelTable extends Migration
{
    public function up(): void
    {
        $this->forge->dropTable('cookie_read_model', true);
    }

    public function down(): void
    {
        $this->forge->addField([
            'cookie_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
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
            ],
            'name_search' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'price_minor' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false,
                'default' => 0,
            ],
            'price_currency' => [
                'type' => 'CHAR',
                'constraint' => 3,
                'null' => false,
                'default' => 'USD',
            ],
            'price_decimal' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => false,
                'default' => 0,
            ],
            'price_formatted' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => false,
                'default' => '',
            ],
            'stock' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => false,
                'default' => 0,
            ],
            'is_active' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => 1,
            ],
            'available' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => 0,
            ],
            'version' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
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
            'projected_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('cookie_id');
        $this->forge->addKey(['tenant_id', 'is_active', 'deleted_at']);
        $this->forge->addKey(['tenant_id', 'name_search']);
        $this->forge->addKey('available');

        $this->forge->createTable('cookie_read_model', true);
    }
}

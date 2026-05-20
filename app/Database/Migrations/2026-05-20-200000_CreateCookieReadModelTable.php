<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cookie read model (D15).
 *
 * Denormalised projection of the Cookie aggregate, populated by event
 * handlers and consumed by list/search queries. Decoupling the read path
 * from the write tables means we can:
 *
 *  - tune indexes for the queries that actually run (here: name search,
 *    active filter, tenant scoping) without touching the canonical write
 *    schema;
 *  - precompute derived fields (`price_formatted`, `available`) so the
 *    UI doesn't recompute them per row;
 *  - rebuild the projection by replaying events (see
 *    `php spark projections:rebuild cookie`).
 *
 * The projection is eventually consistent with the write side. Same-process
 * synchronous event dispatch keeps the gap tiny in normal operation; the
 * outbox + relay (C2) keeps it bounded under failure.
 */
class CreateCookieReadModelTable extends Migration
{
    public function up(): void
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
        // The two common list filters land on the same composite index.
        $this->forge->addKey(['tenant_id', 'name_search']);
        $this->forge->addKey('available');

        $this->forge->createTable('cookie_read_model', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('cookie_read_model', true);
    }
}

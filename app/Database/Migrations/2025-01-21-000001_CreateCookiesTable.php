<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to create the cookies table.
 *
 * Table Structure:
 * - id: Primary key (auto-increment)
 * - name: Cookie name (3-100 chars, globally unique, indexed)
 * - description: Cookie description (optional)
 * - price: Cookie price (decimal(10,2), must be > 0)
 * - stock: Stock quantity (integer, >= 0)
 * - is_active: Active status (boolean, default true, indexed)
 * - created_at: Creation timestamp
 * - updated_at: Last update timestamp
 * - deleted_at: Soft delete timestamp (nullable)
 *
 * Indexes:
 * - PRIMARY: id
 * - UNIQUE: name (reserved after soft delete for ERP/audit history)
 * - INDEX: is_active (for filtering active cookies)
 * - INDEX: deleted_at (for soft delete queries)
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
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
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

        // Primary key
        $this->forge->addPrimaryKey('id');

        // Names remain reserved after soft delete to preserve historical references.
        $this->forge->addUniqueKey('name');

        // Index on is_active for filtering
        $this->forge->addKey('is_active');

        // Index on deleted_at for soft delete queries
        $this->forge->addKey('deleted_at');

        // Create table
        $this->forge->createTable('cookies', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('cookies', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddNameToUsersTable extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('name', 'users')) {
            return;
        }

        $this->forge->addColumn('users', [
            'name' => [
                'type' => 'VARCHAR',
                'constraint' => '100',
                'null' => false,
                'after' => 'id',
            ],
        ]);
    }

    public function down(): void
    {
        // Keep rollback portable across SQLite test databases. The following
        // CreateUsersTable::down() migration drops the table during full
        // refreshes, and preserving data columns is safer than destructive
        // column surgery in a shared ERP template.
    }
}

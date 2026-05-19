<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create token_blacklist table for logout functionality.
 *
 * SECURITY:
 * - Blacklisted tokens rejected even with valid signature
 * - jti (JWT ID) stored, not full token
 * - Automatic cleanup of expired entries
 * - Index on jti for constant-time lookups
 */
final class CreateTokenBlacklistTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'jti' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'comment' => 'JWT ID (unique token identifier)',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'comment' => 'When token naturally expires',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'comment' => 'When token was blacklisted',
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('jti', false, true); // Unique index
        $this->forge->addKey('expires_at');

        $this->forge->createTable('token_blacklist');
    }

    public function down(): void
    {
        $this->forge->dropTable('token_blacklist');
    }
}

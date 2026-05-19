<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create password_reset_tokens table.
 *
 * SECURITY:
 * - Cryptographically secure tokens (SHA-256 hashed)
 * - Short expiration (1 hour)
 * - Single-use (deleted after use)
 * - Token indexed for fast lookup
 */
final class CreatePasswordResetTokensTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type' => 'INT',
                'unsigned' => true,
            ],
            'token_hash' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'comment' => 'SHA-256 hash of reset token',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('token_hash', false, true); // Unique index
        $this->forge->addKey('user_id');
        $this->forge->addKey('expires_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('password_reset_tokens');
    }

    public function down(): void
    {
        $this->forge->dropTable('password_reset_tokens');
    }
}

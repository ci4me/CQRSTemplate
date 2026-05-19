<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create refresh_tokens table for token rotation.
 *
 * SECURITY:
 * - One refresh token per user session
 * - Single-use tokens (replaced on refresh)
 * - Automatic cleanup of expired tokens
 * - jti indexed for fast lookup
 */
final class CreateRefreshTokensTable extends Migration
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
            'jti' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'comment' => 'JWT ID (unique token identifier)',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'revoked' => [
                'type' => 'BOOLEAN',
                'default' => false,
                'comment' => 'Revoked tokens cannot be used',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('jti');
        $this->forge->addKey('user_id');
        $this->forge->addKey('expires_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('refresh_tokens');
    }

    public function down(): void
    {
        $this->forge->dropTable('refresh_tokens');
    }
}

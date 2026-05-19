<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create sessions table for active session tracking.
 *
 * SECURITY:
 * - Tracks all active user sessions
 * - Enables concurrent session limits
 * - Supports device-based session management
 * - Facilitates forced logout (revoke all sessions)
 * - Provides audit trail of user activity
 */
final class CreateSessionsTable extends Migration
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
            'access_token_jti' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'comment' => 'JWT ID from access token',
            ],
            'refresh_token_jti' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'comment' => 'JWT ID from refresh token',
            ],
            'ip_address' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'comment' => 'IPv4 or IPv6 address',
            ],
            'user_agent' => [
                'type' => 'TEXT',
                'null' => true,
                'comment' => 'Browser/device information',
            ],
            'device_fingerprint' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
                'comment' => 'SHA-256 hash of device characteristics',
            ],
            'last_activity_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'comment' => 'Last API request timestamp',
            ],
            'expires_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'comment' => 'When refresh token expires',
            ],
            'revoked' => [
                'type' => 'BOOLEAN',
                'default' => false,
                'comment' => 'Manually revoked by user',
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
        $this->forge->addKey('user_id');
        $this->forge->addKey('access_token_jti', false, true); // Unique
        $this->forge->addKey('refresh_token_jti', false, true); // Unique
        $this->forge->addKey('last_activity_at');
        $this->forge->addKey('expires_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('sessions');
    }

    public function down(): void
    {
        $this->forge->dropTable('sessions');
    }
}

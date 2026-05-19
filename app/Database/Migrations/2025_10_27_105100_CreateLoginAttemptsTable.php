<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create login_attempts table for security monitoring.
 *
 * SECURITY:
 * - Tracks all login attempts (success and failure)
 * - Enables brute force detection
 * - Provides audit trail for investigations
 * - Supports account lockout policies
 * - Identifies credential stuffing attacks
 */
final class CreateLoginAttemptsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'email' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'comment' => 'Email used in login attempt',
            ],
            'user_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'comment' => 'User ID if account exists',
            ],
            'ip_address' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
            ],
            'user_agent' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'success' => [
                'type' => 'BOOLEAN',
                'default' => false,
                'comment' => 'Whether login succeeded',
            ],
            'failure_reason' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => true,
                'comment' => 'Why login failed (invalid_password, account_locked, etc.)',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('email');
        $this->forge->addKey('user_id');
        $this->forge->addKey('ip_address');
        $this->forge->addKey('success');
        $this->forge->addKey('created_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('login_attempts');
    }

    public function down(): void
    {
        $this->forge->dropTable('login_attempts');
    }
}

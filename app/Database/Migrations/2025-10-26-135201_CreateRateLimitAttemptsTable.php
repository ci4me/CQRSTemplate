<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * CreateRateLimitAttemptsTable Migration
 *
 * Creates the rate_limit_attempts table for persistent rate limiting tracking.
 * Supports audit trail and long-term blocking strategies.
 */
final class CreateRateLimitAttemptsTable extends Migration
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
            'identifier' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'comment' => 'IP address or user ID for rate limit tracking',
            ],
            'attempt_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
                'comment' => 'Number of attempts in current window',
            ],
            'window_start' => [
                'type' => 'DATETIME',
                'null' => false,
                'comment' => 'Start time of the current rate limit window',
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'comment' => 'When this rate limit window expires',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('identifier');
        $this->forge->addKey('expires_at');
        $this->forge->createTable('rate_limit_attempts');
    }

    public function down(): void
    {
        $this->forge->dropTable('rate_limit_attempts', true);
    }
}

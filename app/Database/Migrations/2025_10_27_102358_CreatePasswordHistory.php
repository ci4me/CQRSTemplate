<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Password History Migration.
 *
 * Creates password_history table to track user password changes.
 * Prevents password reuse by maintaining history of last N passwords.
 *
 * SECURITY:
 * - Stores password hashes (never plaintext)
 * - Indexed for fast lookups
 * - Foreign key ensures referential integrity
 * - Supports password reuse prevention policies
 */
final class CreatePasswordHistory extends Migration
{
    /**
     * Create password_history table.
     */
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'comment'    => 'User who changed password',
            ],
            'password_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'comment'    => 'Argon2ID hashed password',
            ],
            'created_at' => [
                'type'    => 'TIMESTAMP',
                'null'    => true,
                'comment' => 'When password was changed',
            ],
        ]);

        // Primary key
        $this->forge->addKey('id', true);

        // Index on user_id for fast queries
        $this->forge->addKey('user_id');

        // Index on created_at for cleanup queries
        $this->forge->addKey('created_at');

        // Foreign key to users table
        $this->forge->addForeignKey(
            'user_id',
            'users',
            'id',
            'CASCADE',
            'CASCADE'
        );

        $this->forge->createTable('password_history');
    }

    /**
     * Drop password_history table.
     */
    public function down(): void
    {
        $this->forge->dropTable('password_history');
    }
}

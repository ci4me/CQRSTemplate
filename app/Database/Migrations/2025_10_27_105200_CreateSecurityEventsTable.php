<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create security_events table for comprehensive security audit trail.
 *
 * SECURITY:
 * - Records all security-relevant events
 * - Enables forensic analysis
 * - Supports compliance requirements (SOC2, PCI-DSS, GDPR)
 * - Facilitates incident response
 * - Provides anomaly detection data
 */
final class CreateSecurityEventsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'event_type' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'comment' => 'Type of security event',
            ],
            'severity' => [
                'type' => 'ENUM',
                'constraint' => ['low', 'medium', 'high', 'critical'],
                'default' => 'low',
            ],
            'user_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'ip_address' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
            ],
            'description' => [
                'type' => 'TEXT',
                'comment' => 'Human-readable description',
            ],
            'metadata' => [
                'type' => 'JSON',
                'null' => true,
                'comment' => 'Additional context (user_agent, request_data, etc.)',
            ],
            'created_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('event_type');
        $this->forge->addKey('severity');
        $this->forge->addKey('user_id');
        $this->forge->addKey('created_at');

        $this->forge->addForeignKey('user_id', 'users', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('security_events');
    }

    public function down(): void
    {
        $this->forge->dropTable('security_events');
    }
}

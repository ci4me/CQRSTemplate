<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Generic audit log capturing every command dispatched through the CommandBus.
 *
 * Distinct from `security_events` (which is auth-specific). One row per
 * command, written by {@see \App\Infrastructure\Bus\Middleware\AuditMiddleware}
 * INSIDE the command's own transaction so the audit trail commits or rolls
 * back atomically with the business change.
 *
 * Columns:
 * - command_class    : FQCN of the command (e.g. App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand)
 * - actor_id         : 0 for system actor, otherwise users.id
 * - tenant_id        : tenant scope at time of write (nullable until tenancy lands)
 * - correlation_id   : request-scoped trace id (matches log entries)
 * - status           : 'success' | 'failure'
 * - payload_digest   : SHA-256 of a JSON-encoded redacted payload, NOT the
 *                      payload itself — protects PII while still letting
 *                      auditors detect tampering or duplicate dispatches
 * - error_class      : exception FQCN on failure
 * - error_message    : exception message on failure (already redacted by logger)
 * - duration_ms      : end-to-end command duration including middlewares
 * - occurred_at      : timestamp the audit row was written
 *
 * Indexes:
 * - INDEX(command_class)  : "what happened to entity X" queries
 * - INDEX(actor_id)       : "what did this user do today"
 * - INDEX(correlation_id) : "what else happened during request X"
 * - INDEX(occurred_at)    : time-range reports
 */
class CreateAuditLogTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'command_class' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'actor_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'correlation_id' => [
                'type' => 'VARCHAR',
                'constraint' => 128,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => false,
            ],
            'payload_digest' => [
                'type' => 'CHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'error_class' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'error_message' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'duration_ms' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'null' => true,
            ],
            'occurred_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('command_class');
        $this->forge->addKey('actor_id');
        $this->forge->addKey('correlation_id');
        $this->forge->addKey('occurred_at');

        $this->forge->createTable('audit_log', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('audit_log', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Database-backed job queue (D6).
 *
 * Long-running work — sending an invoice email, generating a PDF, syncing
 * with an external system — does not belong on the request path. Commands
 * push a job here; a worker (`spark jobs:work`) picks them up and runs
 * them.
 *
 * Columns:
 *  - handler_class : FQCN of a class implementing JobHandlerInterface
 *  - payload       : JSON-encoded arguments passed to the handler
 *  - queue         : logical channel name; the worker can subscribe to one
 *                    or many ("default", "emails", "reports")
 *  - status        : pending | reserved | done | failed
 *  - attempts      : how many times the worker has tried this job
 *  - max_attempts  : per-row retry budget (default 5)
 *  - available_at  : earliest time the worker may pick this job up;
 *                    used for scheduled / delayed jobs
 *  - reserved_at   : when a worker claimed the job (for stuck-job recovery)
 *  - last_error    : last failure message
 *  - correlation_id: original request's correlation id
 *
 * Status transitions:
 *   pending -> reserved -> done     (success)
 *   pending -> reserved -> pending  (retry, with backoff bumping available_at)
 *   pending -> reserved -> failed   (retry budget exhausted)
 *
 * The worker claims a row by flipping pending -> reserved in a single
 * UPDATE so multiple workers cannot grab the same job.
 */
class CreateJobsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'queue' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
                'default' => 'default',
            ],
            'handler_class' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'payload' => [
                'type' => 'LONGTEXT',
                'null' => false,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => false,
                'default' => 'pending',
            ],
            'attempts' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'max_attempts' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
                'default' => 5,
            ],
            'available_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'reserved_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'correlation_id' => [
                'type' => 'VARCHAR',
                'constraint' => 128,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        // Worker pick-next-pending query.
        $this->forge->addKey(['queue', 'status', 'available_at']);
        $this->forge->addKey('correlation_id');

        $this->forge->createTable('jobs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('jobs', true);
    }
}

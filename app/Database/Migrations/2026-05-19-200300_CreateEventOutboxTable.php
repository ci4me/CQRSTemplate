<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transactional outbox for domain events (C2).
 *
 * Aggregates accumulate events via the AggregateRoot trait; the repository
 * persists those events into THIS table inside the same transaction that
 * commits the entity write. A separate relay process (`spark events:relay`)
 * then drains pending rows and forwards them to the in-process
 * EventDispatcher (or future external bus).
 *
 * Why an outbox instead of synchronous dispatch:
 * - Same-transaction guarantee: event row commits or rolls back with the
 *   business write. No "wrote entity, lost event" failure mode.
 * - Async delivery: long-running listeners (email, webhooks) no longer
 *   block the request.
 * - Replay: a failed listener can be retried without re-running the
 *   command. The relay tracks attempts and last_error.
 *
 * Status lifecycle:
 *   pending -> in_flight -> delivered     (success)
 *   pending -> in_flight -> failed        (retry exhausted)
 *
 * The relay claims a row by flipping pending -> in_flight in a single
 * UPDATE so multiple workers cannot dispatch the same event.
 */
class CreateEventOutboxTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'aggregate_type' => [
                'type' => 'VARCHAR',
                'constraint' => 100,
                'null' => false,
            ],
            'aggregate_id' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'event_class' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'payload' => [
                'type' => 'LONGTEXT',
                'null' => false,
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
                'default' => 'pending',
            ],
            'attempts' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'last_error' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'available_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'occurred_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'delivered_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        // The relay's "pick next pending" query — pending rows due now,
        // ordered by oldest occurred_at.
        $this->forge->addKey(['status', 'available_at']);
        $this->forge->addKey('correlation_id');
        $this->forge->addKey(['aggregate_type', 'aggregate_id']);

        $this->forge->createTable('event_outbox', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('event_outbox', true);
    }
}

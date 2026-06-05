<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E12 outbox hardening (round-4 R2).
 *
 * Closes the four schema gaps the round-3/round-4 audits flagged on
 * `event_outbox`:
 *
 *  1. `status` widened VARCHAR(16) -> VARCHAR(32). The relay already
 *     writes the 18-character terminal status `unsupported_schema`,
 *     which silently truncated (or errored under strict sql_mode).
 *  2. `event_uuid` (UUIDv4 from the E04 AbstractDomainEvent envelope) +
 *     UNIQUE index. The append-side dedup anchor and the consumer-side
 *     dedup key for at-least-once delivery. Nullable so pre-E04 rows
 *     stay valid (both engines allow multiple NULLs under UNIQUE).
 *  3. `lease_expires_at` — claim lease for the in_flight reaper
 *     ({@see \App\Infrastructure\Outbox\EventOutboxRelay::reclaimExpiredLeases()}).
 *  4. `tenant_id` — lets multi-tenant deployments partition relay work
 *     and audit queries per tenant (stamped by a follow-up writer change
 *     once tenancy hardening lands end-to-end).
 *
 * All steps are guarded (fieldExists / information_schema) so the
 * migration is re-runnable and never issues failing DDL.
 */
final class HardenEventOutboxTable extends Migration
{
    private const string UUID_INDEX = 'event_outbox_event_uuid_unique';

    public function up(): void
    {
        $platform = strtolower($this->db->getPlatform());

        $columns = [];
        if (!$this->db->fieldExists('event_uuid', 'event_outbox')) {
            $columns['event_uuid'] = [
                'type' => 'VARCHAR',
                'constraint' => 36,
                'null' => true,
                'after' => 'event_class',
            ];
        }
        if (!$this->db->fieldExists('lease_expires_at', 'event_outbox')) {
            $columns['lease_expires_at'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'attempts',
            ];
        }
        if (!$this->db->fieldExists('tenant_id', 'event_outbox')) {
            $columns['tenant_id'] = [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
                'after' => 'aggregate_id',
            ];
        }

        if ($columns !== []) {
            $this->forge->addColumn('event_outbox', $columns);
        }

        if ($platform === 'sqlite3') {
            // SQLite: column affinity makes the VARCHAR(16) -> (32) widen a
            // no-op (TEXT), so only the unique index is needed.
            $this->db->query(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . self::UUID_INDEX . ' ON event_outbox (event_uuid)'
            );
            return;
        }

        // MySQL/MariaDB: widen status, then add the unique index when absent.
        $this->db->query(
            "ALTER TABLE event_outbox MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending'"
        );

        if (!$this->uuidIndexExists()) {
            $this->db->query(
                'CREATE UNIQUE INDEX ' . self::UUID_INDEX . ' ON event_outbox (event_uuid)'
            );
        }
    }

    public function down(): void
    {
        $platform = strtolower($this->db->getPlatform());

        if ($platform === 'sqlite3') {
            $this->db->query('DROP INDEX IF EXISTS ' . self::UUID_INDEX);
        } elseif ($this->uuidIndexExists()) {
            $this->db->query('DROP INDEX ' . self::UUID_INDEX . ' ON event_outbox');
        }

        foreach (['event_uuid', 'lease_expires_at', 'tenant_id'] as $column) {
            if ($this->db->fieldExists($column, 'event_outbox')) {
                $this->forge->dropColumn('event_outbox', $column);
            }
        }

        if ($platform !== 'sqlite3') {
            $this->db->query(
                "ALTER TABLE event_outbox MODIFY status VARCHAR(16) NOT NULL DEFAULT 'pending'"
            );
        }
    }

    private function uuidIndexExists(): bool
    {
        $rows = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_outbox' AND INDEX_NAME = ?",
            [self::UUID_INDEX]
        )->getResultArray();

        return $rows !== [];
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * E12 Outbox hardening migration.
 *
 * - Widens `status` to VARCHAR(32) + CHECK constraint (closes F-O8)
 * - Adds `event_uuid` CHAR(36) NOT NULL UNIQUE (closes F-I2 idempotency)
 * - Adds lease columns `reserved_at`, `reserved_by` (closes F-I3 claim semantics)
 * - Adds `tenant_id` (closes F-I4)
 * - Adds covering index `(status, available_at, id)` for SKIP LOCKED claim query
 * - Adds tenant index
 *
 * Payload remains LONGTEXT (MySQL JSON type not required for relay; app-level validation in E12 relay).
 * Audit_log entity_type/entity_id addition is separate (see E12 plan).
 *
 * This migration is DESTRUCTIVE — run on staging first.
 */
class HardenEventOutboxTable extends Migration
{
    public function up(): void
    {
        // Widen status + CHECK
        $this->db->query("ALTER TABLE `event_outbox` MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'pending'");
        $this->db->query("ALTER TABLE `event_outbox` ADD CONSTRAINT `chk_status` CHECK (`status` IN ('pending', 'in_flight', 'delivered', 'failed', 'unsupported_schema'))");

        // event_uuid UNIQUE (idempotency)
        $this->db->query("ALTER TABLE `event_outbox` ADD COLUMN `event_uuid` CHAR(36) NOT NULL UNIQUE AFTER `id`");

        // Lease columns
        $this->db->query("ALTER TABLE `event_outbox` ADD COLUMN `reserved_at` DATETIME NULL AFTER `available_at`");
        $this->db->query("ALTER TABLE `event_outbox` ADD COLUMN `reserved_by` VARCHAR(64) NULL AFTER `reserved_at`");
        $this->db->query("ALTER TABLE `event_outbox` ADD COLUMN `tenant_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `reserved_by`");

        // Indexes for efficient claim + tenant isolation
        $this->db->query("ALTER TABLE `event_outbox` ADD INDEX `idx_claim` (`status`, `available_at`, `id`)");
        $this->db->query("ALTER TABLE `event_outbox` ADD INDEX `idx_tenant` (`tenant_id`)");
    }

    public function down(): void
    {
        // Revert is best-effort (destructive migration)
        $this->db->query("ALTER TABLE `event_outbox` DROP COLUMN `event_uuid`");
        $this->db->query("ALTER TABLE `event_outbox` DROP COLUMN `reserved_at`");
        $this->db->query("ALTER TABLE `event_outbox` DROP COLUMN `reserved_by`");
        $this->db->query("ALTER TABLE `event_outbox` DROP COLUMN `tenant_id`");
        $this->db->query("ALTER TABLE `event_outbox` DROP INDEX `idx_claim`");
        $this->db->query("ALTER TABLE `event_outbox` DROP INDEX `idx_tenant`");
        $this->db->query("ALTER TABLE `event_outbox` DROP CONSTRAINT `chk_status`");
        $this->db->query("ALTER TABLE `event_outbox` MODIFY COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'pending'");
    }
}

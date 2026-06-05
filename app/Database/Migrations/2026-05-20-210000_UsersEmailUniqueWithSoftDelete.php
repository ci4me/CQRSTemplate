<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Replace the column-level UNIQUE(email) constraint with a composite
 * UNIQUE(email, deleted_at) so a soft-deleted user does not permanently
 * block re-registration on the same address.
 *
 * Engine caveat: on MySQL/SQLite, NULL values are considered distinct from
 * each other, so this composite UNIQUE behaves correctly for soft-deleted
 * rows (each one has a distinct deleted_at timestamp) but does NOT prevent
 * two simultaneous *active* rows from existing if both have deleted_at IS
 * NULL. Application-level uniqueness via {@see UserRepository::findByEmail}
 * still gates account creation; the index is here as a database-level
 * safety net and to give an honest schema for downstream tooling.
 *
 * Postgres-class engines can use a partial unique index instead
 * (`CREATE UNIQUE INDEX ... WHERE deleted_at IS NULL`); a follow-up
 * migration can swap to that on Postgres by checking $db->getPlatform().
 */
final class UsersEmailUniqueWithSoftDelete extends Migration
{
    public function up(): void
    {
        $platform = strtolower($this->db->getPlatform());

        if ($platform === 'sqlite3') {
            // SQLite: NEVER attempt dropKey here. The column-level UNIQUE is
            // an auto-index that cannot be dropped, and the failed DDL is
            // logged at ERROR by the connection layer even when the throw is
            // caught — a full test run used to append ~92k copies of
            // "SQLite3Exception: no such index: users_email" (round-4 R1).
            // Adding the composite index alongside is enough — uniqueness on
            // (email, deleted_at) is strictly weaker than uniqueness on
            // email alone, so the original constraint stays as a backstop.
            $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS users_email_deleted_unique ON users (email, deleted_at)');
            return;
        }

        // MySQL/MariaDB: the original migration set `'unique' => true` on the
        // `email` column; the resulting index name varies (`email`,
        // `users_email`, ...). Discover the real single-column index name
        // from information_schema instead of guessing-and-catching.
        $indexName = $this->findSingleColumnEmailIndexName();
        if ($indexName !== null) {
            $this->forge->dropKey('users', $indexName, false);
        }

        $this->forge->addKey(['email', 'deleted_at'], false, true, 'users_email_deleted_unique');
        $this->forge->processIndexes('users');
    }

    /**
     * Find the name of a single-column index covering ONLY users.email.
     *
     * Excludes the composite index this migration creates and the primary
     * key. Returns null when no such index exists (fresh schema or already
     * migrated) — the caller then skips the drop entirely, so no failing
     * DDL is ever issued.
     */
    private function findSingleColumnEmailIndexName(): ?string
    {
        $rows = $this->db->query(
            "SELECT INDEX_NAME, COUNT(*) AS column_count,
                    SUM(CASE WHEN COLUMN_NAME = 'email' THEN 1 ELSE 0 END) AS email_columns
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
             GROUP BY INDEX_NAME"
        )->getResultArray();

        foreach ($rows as $row) {
            $name = (string) ($row['INDEX_NAME'] ?? '');
            $isSingleEmailIndex = (int) ($row['column_count'] ?? 0) === 1
                && (int) ($row['email_columns'] ?? 0) === 1;

            if (
                $isSingleEmailIndex
                && $name !== ''
                && $name !== 'users_email_deleted_unique'
                && strtoupper($name) !== 'PRIMARY'
            ) {
                return $name;
            }
        }

        return null;
    }

    public function down(): void
    {
        $platform = strtolower($this->db->getPlatform());

        if ($platform === 'sqlite3') {
            $this->db->query('DROP INDEX IF EXISTS users_email_deleted_unique');
            return;
        }

        try {
            $this->forge->dropKey('users', 'users_email_deleted_unique', false);
        } catch (\Throwable) {
            // best-effort: roll-back is cosmetic on dev databases.
        }
    }
}

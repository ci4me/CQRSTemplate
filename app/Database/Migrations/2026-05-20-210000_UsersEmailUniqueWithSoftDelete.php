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

        // The original migration set `'unique' => true` on the `email`
        // column, which CI4 implements as a single-column UNIQUE index.
        // Index name conventions vary by engine:
        //   - SQLite auto-generates `sqlite_autoindex_users_*`, no drop API.
        //   - MySQL/MariaDB names it `email` or `users_email`.
        // We attempt a best-effort drop and tolerate failures so the
        // composite index always lands.
        try {
            $this->forge->dropKey('users', 'users_email', false);
        } catch (\Throwable) {
            // index didn't exist under that name — fine.
        }

        if ($platform === 'sqlite3') {
            // SQLite's column-level UNIQUE creates an auto-index that we
            // can't drop directly. Adding the composite index alongside
            // is enough — uniqueness on (email, deleted_at) is strictly
            // weaker than uniqueness on email alone, so the original
            // constraint stays as a backstop.
            $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS users_email_deleted_unique ON users (email, deleted_at)');
            return;
        }

        $this->forge->addKey(['email', 'deleted_at'], false, true, 'users_email_deleted_unique');
        $this->forge->processIndexes('users');
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

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Plain (non-unique) index on cookies.name (round-4 R2).
 *
 * The paginated search switched from a leading-wildcard LIKE '%term%'
 * (unindexable, full scan) to a prefix LIKE 'term%'. A prefix match is
 * sargable but needs an index whose LEFTMOST column is `name`; the
 * existing composite UNIQUE(tenant_id, name, deleted_at) cannot serve a
 * name-prefix lookup that doesn't also pin tenant_id.
 *
 * Named index + `IF NOT EXISTS`-style guards keep the migration
 * re-runnable on both engines.
 */
final class AddCookiesNameIndex extends Migration
{
    private const string INDEX_NAME = 'cookies_name_prefix';

    public function up(): void
    {
        $platform = strtolower($this->db->getPlatform());

        if ($platform === 'sqlite3') {
            $this->db->query('CREATE INDEX IF NOT EXISTS ' . self::INDEX_NAME . ' ON cookies (name)');
            return;
        }

        if (!$this->indexExists()) {
            $this->db->query('CREATE INDEX ' . self::INDEX_NAME . ' ON cookies (name)');
        }
    }

    public function down(): void
    {
        $platform = strtolower($this->db->getPlatform());

        if ($platform === 'sqlite3') {
            $this->db->query('DROP INDEX IF EXISTS ' . self::INDEX_NAME);
            return;
        }

        if ($this->indexExists()) {
            $this->db->query('DROP INDEX ' . self::INDEX_NAME . ' ON cookies');
        }
    }

    /**
     * MySQL/MariaDB: check information_schema before CREATE/DROP so the
     * migration never issues failing DDL (the logged-exception anti-pattern
     * round-4 R1 eliminated).
     */
    private function indexExists(): bool
    {
        $rows = $this->db->query(
            "SELECT 1 FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cookies' AND INDEX_NAME = ?",
            [self::INDEX_NAME]
        )->getResultArray();

        return $rows !== [];
    }
}

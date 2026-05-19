<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storage for document number sequences (D4).
 *
 * Each (series, scope) row carries the current counter and a configurable
 * prefix/suffix/pad-length. A sequence is named by its series (e.g.
 * "invoice", "purchase_order", "credit_note") and optionally scoped by a
 * second key (e.g. fiscal year, tenant_id, company_id). That second key is
 * stored as an opaque string ("2026", "tenant:7"), letting different
 * domains pick whatever scoping fits.
 *
 * Each call to allocate() bumps `current_value` inside a transaction, so a
 * gapless sequence per scope is guaranteed as long as the issuing
 * transaction commits.
 *
 * Format example:
 *   series  = "invoice"
 *   scope   = "2026"
 *   prefix  = "INV-2026-"
 *   padding = 5
 *   produces INV-2026-00001, INV-2026-00002, ...
 */
class CreateDocumentSequencesTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'series' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
            ],
            'scope' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
                'default' => '',
            ],
            'prefix' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => false,
                'default' => '',
            ],
            'suffix' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => false,
                'default' => '',
            ],
            'pad_length' => [
                'type' => 'TINYINT',
                'unsigned' => true,
                'null' => false,
                'default' => 1,
            ],
            'current_value' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
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
        // Lookup key — exactly one row per (series, scope).
        $this->forge->addUniqueKey(['series', 'scope']);

        $this->forge->createTable('document_sequences', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('document_sequences', true);
    }
}

<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Runtime configuration store (D10).
 *
 * Some configuration is operational rather than deployment-time:
 *  - default tax rate, payment terms, default warehouse
 *  - feature flags ("enable_new_export", "show_v2_dashboard")
 *  - tenant-scoped preferences once tenancy lands
 *
 * Storing these in PHP config files would require a deploy to change. The
 * settings table lets the application read them at runtime; the future
 * SettingsController will allow administrators to update them via the UI.
 *
 * Columns:
 *  - key         — dot-namespaced identifier (e.g. billing.default_terms_days)
 *  - tenant_id   — null for global settings; bound to a tenant otherwise
 *  - value_json  — JSON-encoded value; the service deserialises on read
 *  - type        — string|int|float|bool|array (for UI hints + validation)
 *  - description — human-friendly label for the admin UI
 *  - is_secret   — when true the UI may render as a password field and
 *                  the value should never appear in logs
 */
class CreateSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'key_name' => [
                'type' => 'VARCHAR',
                'constraint' => 128,
                'null' => false,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'value_json' => [
                'type' => 'LONGTEXT',
                'null' => false,
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => false,
                'default' => 'string',
            ],
            'description' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'is_secret' => [
                'type' => 'TINYINT',
                'constraint' => 1,
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
        $this->forge->addUniqueKey(['key_name', 'tenant_id']);
        $this->forge->addKey('tenant_id');

        $this->forge->createTable('settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('settings', true);
    }
}

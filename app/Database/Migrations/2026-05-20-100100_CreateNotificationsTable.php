<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Per-user in-app notifications (D12).
 *
 * ERPs need non-blocking alerts ("invoice approved", "stock low",
 * "approval required") that the recipient can see in the UI without an
 * email. This table holds them. Sending email/Slack/etc. is delivered
 * separately via the job queue (D6) or future Notifier service.
 *
 * Columns:
 *   user_id      — the recipient
 *   tenant_id    — tenant scope (nullable until tenancy lands)
 *   type         — short identifier ("invoice.approved", "stock.low")
 *                  for grouping/filtering and i18n keys
 *   title        — short summary, plain text
 *   body         — optional longer body, may be plain or markdown
 *   data_json    — extra structured payload (entity id, action URL, etc.)
 *   url          — optional click-through path inside the app
 *   level        — info | success | warning | error (UI hint)
 *   read_at      — null = unread; UI flips when user acknowledges
 *   correlation_id — the request that produced the notification
 */
class CreateNotificationsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => true,
            ],
            'type' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
            ],
            'title' => [
                'type' => 'VARCHAR',
                'constraint' => 200,
                'null' => false,
            ],
            'body' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'data_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'url' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'level' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => false,
                'default' => 'info',
            ],
            'read_at' => [
                'type' => 'DATETIME',
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
        ]);

        $this->forge->addPrimaryKey('id');
        // The most common query: "this user's unread feed" — ordered by created_at desc.
        $this->forge->addKey(['user_id', 'read_at']);
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey('correlation_id');
        // Notifications die with the user — no point keeping inbox rows
        // for a hard-deleted account. CASCADE on delete; users.id is
        // never updated, so update behaviour is purely defensive.
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('notifications', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('notifications', true);
    }
}

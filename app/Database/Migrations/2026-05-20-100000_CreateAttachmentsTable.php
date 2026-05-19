<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Attachments registry (D11).
 *
 * ERPs need to attach scanned invoices, signed POs, tax forms, PDF
 * receipts, etc. to business records. The attachment row holds the
 * metadata + a storage_key that the {@see \App\Infrastructure\Storage\StorageInterface}
 * resolves to actual bytes (local disk, S3, ...).
 *
 * Polymorphic association via (attachable_type, attachable_id) lets one
 * table serve every domain. Composite index keeps "list attachments for
 * this entity" queries fast.
 *
 * Columns:
 *  - attachable_type : FQCN of the owner aggregate (e.g. App\Domain\Cookie\Entities\Cookie)
 *  - attachable_id   : owner aggregate id, opaque string
 *  - storage_key     : path/identifier inside the storage backend
 *  - storage_driver  : which backend wrote the bytes (local|s3|...)
 *  - original_name   : human-readable filename for download
 *  - mime_type       : as-detected MIME type
 *  - size_bytes      : raw size
 *  - checksum_sha256 : integrity guard
 *  - uploaded_by     : actor id (0 = system)
 *  - tenant_id       : tenant scope (nullable until tenancy lands)
 */
class CreateAttachmentsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'attachable_type' => [
                'type' => 'VARCHAR',
                'constraint' => 191,
                'null' => false,
            ],
            'attachable_id' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => false,
            ],
            'storage_key' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'storage_driver' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
                'null' => false,
                'default' => 'local',
            ],
            'original_name' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'mime_type' => [
                'type' => 'VARCHAR',
                'constraint' => 127,
                'null' => false,
                'default' => 'application/octet-stream',
            ],
            'size_bytes' => [
                'type' => 'BIGINT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'checksum_sha256' => [
                'type' => 'CHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'uploaded_by' => [
                'type' => 'INT',
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'tenant_id' => [
                'type' => 'INT',
                'unsigned' => true,
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
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['attachable_type', 'attachable_id']);
        $this->forge->addKey('storage_key');
        $this->forge->addKey('tenant_id');

        $this->forge->createTable('attachments', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('attachments', true);
    }
}

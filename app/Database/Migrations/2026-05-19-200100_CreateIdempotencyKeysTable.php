<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storage for idempotency keys (D9).
 *
 * Clients pass `Idempotency-Key: <client-generated-id>` on POST/PUT/PATCH/DELETE
 * requests; the IdempotencyMiddleware persists the response under that key
 * on first invocation and replays it for any subsequent request with the
 * same key inside the retention window.
 *
 * Columns:
 * - id_key      : the key submitted by the client (treated as opaque)
 * - method      : HTTP method (GET keys are not allowed, but we keep it
 *                 in case future code stores something)
 * - uri         : request path (so the same key on a different endpoint
 *                 is rejected as a conflict, per RFC 7807)
 * - actor_id    : tying the key to the actor prevents two unrelated
 *                 users from colliding via a shared GUID
 * - status_code : the response's status code that we captured
 * - response_body: the serialized response body (JSON string)
 * - response_headers: minimal subset to preserve content-type, etc.
 * - request_hash: SHA-256 of method+uri+body — used to detect "same key,
 *                 different request" (which is a 409 conflict)
 * - created_at, expires_at
 *
 * The TTL is enforced at read time; a separate cleanup job (future
 * CleanupJobs scheduler) can prune expired rows.
 */
class CreateIdempotencyKeysTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_key' => [
                'type' => 'VARCHAR',
                'constraint' => 128,
                'null' => false,
            ],
            'actor_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => false,
                'default' => 0,
            ],
            'method' => [
                'type' => 'VARCHAR',
                'constraint' => 10,
                'null' => false,
            ],
            'uri' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'request_hash' => [
                'type' => 'CHAR',
                'constraint' => 64,
                'null' => false,
            ],
            'status_code' => [
                'type' => 'SMALLINT',
                'unsigned' => true,
                'null' => false,
            ],
            'response_body' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'response_headers' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addPrimaryKey('id');
        // (id_key, actor_id) is the lookup key — same client cannot reuse
        // the same key for a different request.
        $this->forge->addUniqueKey(['id_key', 'actor_id']);
        $this->forge->addKey('expires_at');

        $this->forge->createTable('idempotency_keys', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('idempotency_keys', true);
    }
}

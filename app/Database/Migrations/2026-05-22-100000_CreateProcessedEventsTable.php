<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Handler-side at-most-once dedup table (round-3 slice 05/F5 / epic E12.5).
 *
 * Backs {@see \App\Infrastructure\Events\DatabaseProcessedEventStore}.
 * One row per `(event_id, listener_class)` pair the EventDispatcher has
 * successfully invoked. The dispatcher consults this table before calling
 * each listener and skips invocations whose pair is already present —
 * which gives side-effect handlers (webhook senders, email senders) an
 * at-most-once channel that survives worker crashes between a successful
 * listener call and the outbox-ACK.
 *
 * # Why a composite PK (and not a separate id column)
 *
 * The lookup is always `(event_id, listener_class)` — never by surrogate
 * id. A surrogate primary key would force MySQL to maintain a second
 * unique index over the same two columns just to enforce the same
 * uniqueness, doubling write amplification for no benefit.
 *
 * # Why `VARCHAR(190)` on `listener_class`
 *
 * MySQL's InnoDB row-key limit on utf8mb4 is 767 bytes (3072 / 4 ~= 768).
 * A composite PK of CHAR(36) + VARCHAR(N) lands at 36*4 + N*4 = 144 + 4N
 * bytes. N = 190 gives 904 bytes — well under the limit even on legacy
 * utf8mb4_unicode_ci installations that haven't enabled the
 * `innodb_large_prefix` flag. Modern FQCNs fit comfortably (PSR-4
 * canonical names cap out around 120 characters).
 *
 * # Why `CHAR(36)` and not UUID-binary
 *
 * `AbstractDomainEvent::$eventId` ships as a 36-char canonical UUIDv7
 * string. Storing it in binary would save 20 bytes/row but force every
 * dispatcher query through a `UNHEX(?)` cast — fragile, and the
 * EventDispatcher comparison stays a plain string match.
 *
 * # `processed_at` exists for observability only
 *
 * The dedup behaviour does not need a timestamp — the PK enforces
 * uniqueness — but operations needs to be able to answer "when did we
 * actually fire this side effect?" without joining the outbox.
 * `DEFAULT CURRENT_TIMESTAMP` keeps the adapter free of clock concerns
 * for the common case while still allowing explicit override from tests.
 *
 * @package App\Database\Migrations
 */
class CreateProcessedEventsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            // UUIDv7 string from AbstractDomainEvent::$eventId. CHAR(36) is
            // exactly the canonical 8-4-4-4-12 representation including
            // hyphens; VARCHAR would waste 1 byte per row on the length
            // prefix for no benefit.
            'event_id' => [
                'type' => 'CHAR',
                'constraint' => 36,
                'null' => false,
            ],
            // Stable listener identifier — usually the listener's FQCN, or
            // "Class::method" for array callables, or "Closure" for
            // anonymous functions. See EventDispatcher::describeListener().
            'listener_class' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => false,
            ],
            // Set explicitly by the adapter on each mark so the column
            // does not depend on driver-specific DEFAULT CURRENT_TIMESTAMP
            // semantics (SQLite + MySQL differ on column-level defaults).
            'processed_at' => [
                'type' => 'TIMESTAMP',
                'null' => false,
                'default' => new \CodeIgniter\Database\RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);

        // Composite PK = the lookup key + uniqueness enforcer in one. See
        // the class docblock for the row-key budget calculation.
        $this->forge->addPrimaryKey(['event_id', 'listener_class']);

        // ENGINE = InnoDB and charset utf8mb4 / utf8mb4_unicode_ci come
        // from the connection defaults set in `app/Config/Database.php` —
        // passing them here would emit literal `ENGINE = 'InnoDB'`
        // syntax that SQLite (used in the unit test suite) refuses to
        // parse. Match the rest of the migration set, which relies on
        // the same connection-level defaults.
        $this->forge->createTable('processed_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('processed_events', true);
    }
}

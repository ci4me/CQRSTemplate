<?php

declare(strict_types=1);

namespace App\Infrastructure\Events;

use App\Domain\Shared\Events\ProcessedEventStoreInterface;
use App\Infrastructure\Attributes\InfrastructureAdapter;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Database-backed adapter for {@see ProcessedEventStoreInterface}.
 *
 * Implements the handler-side at-most-once dedup table introduced by
 * round-3 slice 05/F5 (epic E12.5). One row per
 * `(event_id, listener_class)` pair lives in the `processed_events`
 * table; the composite primary key gives us O(1) lookups and lets the
 * database itself enforce uniqueness.
 *
 * # Why `INSERT IGNORE` on writes
 *
 * Two workers can race to dispatch the same event after a relay retry.
 * Both call `markProcessed()` with identical keys. A naive `INSERT` would
 * fan one of them into a `Duplicate entry` SQLException; an explicit
 * `INSERT IGNORE` lets MySQL silently drop the duplicate, and CI4's query
 * builder `->ignore(true)` flag compiles to `INSERT IGNORE` on MySQL and
 * to `INSERT OR IGNORE` on SQLite (used in the unit test suite). Either
 * way, the second writer's call is a no-op — which is the
 * `markProcessed()` contract from the port docblock.
 *
 * # Why not a UNIQUE-violating retry loop
 *
 * Catching the PDO duplicate exception and ignoring it works too but
 * couples the adapter to driver-specific SQLSTATE codes. The
 * builder-level `ignore(true)` keeps the adapter portable across MySQL /
 * SQLite / Postgres and keeps the call-site readable.
 *
 * # When NOT to instantiate this class directly
 *
 * Production callers go through {@see \Config\Services::processedEventStore()};
 * the wiring in `Services` shares one instance across the request and
 * binds it onto the `EventDispatcher` setter (see
 * {@see \App\Infrastructure\Bus\EventDispatcher::setProcessedEventStore()}).
 * Direct instantiation is only for unit tests that want to bind a custom
 * `BaseConnection` (typically the in-memory SQLite group provided by
 * `IntegrationTestCase`).
 *
 * @package App\Infrastructure\Events
 */
#[InfrastructureAdapter]
final class DatabaseProcessedEventStore implements ProcessedEventStoreInterface
{
    /**
     * Table backing this adapter. Centralised to avoid the literal drifting
     * between the migration and the adapter — a grep audit can spot any
     * cloner that copies this class but forgets to ship a matching table.
     */
    public const string TABLE = 'processed_events';

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     *        Optional CI4 connection; defaults to the shared `Database::connect()`
     *        handle so production callers don't have to pass it. Tests pass a
     *        fresh in-memory SQLite group via the IntegrationTestCase trait.
     */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * Whether the `(eventId, listenerClass)` pair has already been recorded.
     *
     * Implemented as a `SELECT 1 ... LIMIT 1` for two reasons: the
     * composite PK turns this into an index-only scan with no row
     * materialisation, and `countAllResults()` would have to wrap the
     * builder in a sub-select on some drivers. Existence beats counting
     * every time.
     *
     * @param string $eventId
     * @param string $listenerClass
     * @return bool
     */
    public function isProcessed(string $eventId, string $listenerClass): bool
    {
        $result = $this->connection()->table(self::TABLE)
            ->select('1', false)
            ->where('event_id', $eventId)
            ->where('listener_class', $listenerClass)
            ->limit(1)
            ->get();

        // `get()` returns `false` only on a query error — in which case
        // the caller is better served by surfacing the failure than by
        // silently returning "not processed" and re-firing the listener.
        if ($result === false) {
            throw new \RuntimeException(
                'DatabaseProcessedEventStore: query against "' . self::TABLE . '" failed.'
            );
        }

        return $result->getRowArray() !== null;
    }

    /**
     * Record `(eventId, listenerClass)` as processed. Race-safe via
     * `INSERT IGNORE` (MySQL) / `INSERT OR IGNORE` (SQLite); see the class
     * docblock for the reasoning.
     *
     * @param string $eventId
     * @param string $listenerClass
     * @return void
     */
    public function markProcessed(string $eventId, string $listenerClass): void
    {
        // `ignore(true)` compiles to INSERT IGNORE on MySQL and
        // INSERT OR IGNORE on SQLite. Either way: a duplicate primary
        // key is silently dropped, preserving the port's "safe to call
        // twice" contract.
        $this->connection()->table(self::TABLE)
            ->ignore(true)
            ->insert([
                'event_id' => $eventId,
                'listener_class' => $listenerClass,
                'processed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

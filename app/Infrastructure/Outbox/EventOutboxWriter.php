<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Append-only writer that lets aggregates park their domain events in the
 * `event_outbox` table inside the same transaction as the business write
 * (C2). The {@see \App\Infrastructure\Bus\Middleware\TransactionMiddleware}
 * wraps the whole pipeline, so an outbox INSERT here is committed atomically
 * with the entity save.
 *
 * # Payload envelope (schema_version 1)
 *
 * Each row's `payload` column stores a versioned envelope rather than the
 * raw event body. The shape is:
 *
 * ```json
 * {
 *   "schema_version": 1,
 *   "event_class": "App\\Domain\\Cookie\\Events\\CookieCreated\\CookieCreatedEvent",
 *   "occurred_at": "2026-05-21T10:30:00+00:00",
 *   "correlation_id": "3fd536da-71a6-4e85-8b62-769d4c86b99b",
 *   "payload": { "id": 1, "name": "Chocolate Chip", "price": 9.99 }
 * }
 * ```
 *
 * Why the envelope (SV-1):
 * The day a domain event grows a field (e.g. `CookieCreated` gains
 * `tenant_id`), every in-flight row written under the old shape stops
 * rehydrating cleanly. Stamping `schema_version` on every row lets the
 * relay branch between historic and current readers, and lets us migrate
 * fields forward without rewriting historical rows.
 *
 * # Schema evolution playbook
 *
 * - The current schema version is **1**.
 * - To add a new field / shape change:
 *   1. Bump `schema_version` to `2` in {@see self::SCHEMA_VERSION}.
 *   2. Update the envelope construction below to write v2 going forward.
 *   3. Update {@see EventOutboxRelay::decodeEnvelope()} to teach the relay
 *      how to read BOTH v1 (legacy in-flight rows) AND v2 (new rows).
 *   4. NEVER modify v1's reader once events of that version exist in
 *      production. The reader contract is: every version that has ever
 *      shipped must remain readable until you have evidence (audit query
 *      against `event_outbox`) that no pending rows of that version remain.
 *   5. The relay logs an `unsupported_schema` row and refuses to dispatch
 *      it when it encounters a `schema_version` it doesn't know — that's
 *      the dead-letter signal for a forgotten reader bump.
 *
 * Serialisation of the inner event body stays trivial: each event's
 * public properties go through `get_object_vars` and JSON-encode, or via
 * a `toArray()` method if the event provides one. The envelope is layered
 * on top, not inside the event itself, so events stay free of envelope
 * concerns.
 */
final class EventOutboxWriter
{
    /**
     * Current envelope schema version. Bump in lockstep with a reader
     * update in {@see EventOutboxRelay}. See class-level docblock for the
     * evolution playbook.
     */
    public const int SCHEMA_VERSION = 1;

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * Append a single event row.
     *
     * @param object                  $event         The domain event being recorded.
     * @param string                  $aggregateType FQCN of the aggregate (e.g. Cookie::class).
     * @param int|string|null         $aggregateId   Identifier of the aggregate, if known.
     * @param \DateTimeImmutable|null $availableAt
     * @return void
     *                                If provided, the relay won't pick this row
     *                                up until that wall-clock time. Defaults to
     *                                "now" — typical for events emitted by a
     *                                successful command.
     */
    public function append(
        object $event,
        string $aggregateType,
        int|string|null $aggregateId,
        ?\DateTimeImmutable $availableAt = null
    ): void {
        $now = new \DateTimeImmutable();
        $available = $availableAt ?? $now;
        $correlationId = CorrelationIdService::get();

        $payload = $this->buildEnvelope($event, $now, $correlationId);

        $this->connection()->table('event_outbox')->insert([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId === null ? null : (string) $aggregateId,
            'event_class' => $event::class,
            'payload' => $payload,
            'correlation_id' => $correlationId,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
            'available_at' => $available->format('Y-m-d H:i:s'),
            'occurred_at' => $now->format('Y-m-d H:i:s'),
            'delivered_at' => null,
        ]);
    }

    /**
     * Bulk-append a set of events for the same aggregate (typical flow:
     * `$writer->appendAll($cookie->pullEvents(), Cookie::class, $cookie->getId())`).
     *
     * @param list<object>    $events
     * @param string          $aggregateType
     * @param int|string|null $aggregateId
     * @return void
     */
    public function appendAll(array $events, string $aggregateType, int|string|null $aggregateId): void
    {
        foreach ($events as $event) {
            $this->append($event, $aggregateType, $aggregateId);
        }
    }

    /**
     * Build the versioned envelope around an event's body.
     *
     * @param object             $event
     * @param \DateTimeImmutable $occurredAt
     * @param string             $correlationId
     * @return string JSON-encoded envelope ready for the `payload` column.
     */
    private function buildEnvelope(
        object $event,
        \DateTimeImmutable $occurredAt,
        string $correlationId
    ): string {
        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'event_class' => $event::class,
            'occurred_at' => $occurredAt->format(\DateTimeInterface::ATOM),
            'correlation_id' => $correlationId,
            'payload' => $this->extractEventBody($event),
        ];

        return $this->jsonEncode($envelope);
    }

    /**
     * Extract the raw event body (the inner `payload` of the envelope).
     *
     * Prefers an explicit `toArray()` method when the event exposes one
     * (lets events with value-object fields control their own shape);
     * otherwise falls back to `get_object_vars` on the public state.
     *
     * @param object $event
     * @return array<int|string, mixed>
     */
    private function extractEventBody(object $event): array
    {
        if (method_exists($event, 'toArray')) {
            $array = $event->toArray();
            if (is_array($array)) {
                return $array;
            }
        }

        return get_object_vars($event);
    }

    /**
     * @param array<int|string, mixed> $data
     * @return string
     */
    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

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
 * Serialisation is intentionally trivial: each event's public properties go
 * through `get_object_vars` and JSON-encode. That handles the existing
 * Cookie events (all readonly DTOs with scalar/array fields). When events
 * grow value-object fields, they should expose them via array shape in a
 * `toArray()` method — the writer will look for that first.
 */
final class EventOutboxWriter
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private ?BaseConnection $db = null)
    {
    }

    /**
     * Append a single event row.
     *
     * @param object $event           The domain event being recorded.
     * @param string $aggregateType   FQCN of the aggregate (e.g. Cookie::class).
     * @param int|string|null $aggregateId Identifier of the aggregate, if known.
     * @param \DateTimeImmutable|null $availableAt
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

        $payload = $this->serialiseEvent($event);

        $this->connection()->table('event_outbox')->insert([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId === null ? null : (string) $aggregateId,
            'event_class' => $event::class,
            'payload' => $payload,
            'correlation_id' => CorrelationIdService::get(),
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
     * @param list<object> $events
     */
    public function appendAll(array $events, string $aggregateType, int|string|null $aggregateId): void
    {
        foreach ($events as $event) {
            $this->append($event, $aggregateType, $aggregateId);
        }
    }

    private function serialiseEvent(object $event): string
    {
        if (method_exists($event, 'toArray')) {
            $array = $event->toArray();
            if (is_array($array)) {
                return $this->jsonEncode($array);
            }
        }

        return $this->jsonEncode(get_object_vars($event));
    }

    /**
     * @param array<int|string, mixed> $data
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

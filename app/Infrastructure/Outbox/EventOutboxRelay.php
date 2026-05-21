<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Drains the `event_outbox` table and forwards rows to the in-process
 * EventDispatcher (C2).
 *
 * Designed for a single-worker setup first (spark events:relay run once);
 * the claim semantics (UPDATE ... WHERE status = 'pending' RETURNING id)
 * are safe under multiple workers on Postgres/MySQL. SQLite, used by the
 * test suite, has no RETURNING but our claim is a transaction so concurrent
 * runners still see consistent rows.
 *
 * Retry policy:
 *   - On dispatch failure, attempts++ and available_at += backoff.
 *   - Backoff schedule: 30s, 2 min, 10 min, 1 h, 6 h, 24 h, then `failed`.
 *
 * Replay of events whose listener class no longer exists is logged but
 * marked failed so the row stops cycling.
 *
 * # Envelope versioning (SV-1)
 *
 * The relay understands two payload shapes on disk:
 *
 *  - **Envelope (v1+)** — written by the current
 *    {@see EventOutboxWriter}. JSON object with `schema_version`,
 *    `event_class`, `occurred_at`, `correlation_id`, and the inner event
 *    body under `payload`.
 *  - **Legacy (no schema_version key)** — rows written before SV-1
 *    landed. The JSON is the event body directly. Kept readable forever
 *    so deploying this code does not strand pending rows. See
 *    {@see EventOutboxWriter}'s class docblock for the schema-evolution
 *    playbook.
 *
 * Rows whose `schema_version` is recognised as a numeric value greater
 * than {@see EventOutboxWriter::SCHEMA_VERSION} are NOT dispatched: they
 * are marked with the terminal `unsupported_schema` status and logged.
 * That's the dead-letter signal that this binary is older than its data
 * (e.g. a relay rolled back behind a writer) — operator intervention is
 * required to upgrade the binary before those rows can be replayed.
 */
final class EventOutboxRelay
{
    private const int MAX_ATTEMPTS = 6;
    private const array BACKOFF_SECONDS = [30, 120, 600, 3600, 21600, 86400];

    /**
     * @param EventDispatcher                                                   $dispatcher
     * @param LoggerInterface                                                   $logger
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly ?BaseConnection $db = null
    ) {
    }

    /**
     * Process up to $batchSize pending rows.
     *
     * @param int $batchSize
     * @return array{processed: int, delivered: int, retried: int, failed: int}
     */
    public function drain(int $batchSize = 50): array
    {
        $processed = 0;
        $delivered = 0;
        $retried = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($this->fetchPending($batchSize, $now) as $row) {
            $processed++;
            $result = $this->processRow($row);
            match ($result) {
                'delivered' => $delivered++,
                'retried' => $retried++,
                'failed' => $failed++,
                default => null,
            };
        }

        return [
            'processed' => $processed,
            'delivered' => $delivered,
            'retried' => $retried,
            'failed' => $failed,
        ];
    }

    /**
     * @param int    $batchSize
     * @param string $now
     * @return list<array<string, mixed>>
     */
    private function fetchPending(int $batchSize, string $now): array
    {
        $result = $this->connection()
            ->table('event_outbox')
            ->where('status', 'pending')
            ->where('available_at <=', $now)
            ->orderBy('available_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit($batchSize)
            ->get();

        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return string
     */
    private function processRow(array $row): string
    {
        $id = (int) ($row['id'] ?? 0);
        $eventClass = (string) ($row['event_class'] ?? '');

        // Adopt the original correlation_id for downstream logs so the
        // delivery is traceable to the request that emitted the event.
        $originalCorrelation = (string) ($row['correlation_id'] ?? '');
        if ($originalCorrelation !== '') {
            CorrelationIdService::set($originalCorrelation);
        }

        if (!$this->claim($id)) {
            return 'retried'; // someone else got it; revisit on the next tick
        }

        try {
            $decoded = $this->decodeEnvelope((string) ($row['payload'] ?? '[]'));
        } catch (\Throwable $e) {
            $this->markFailed($id, sprintf('payload decode failed: %s', $e->getMessage()));
            $this->logger->error('Outbox payload decode failed', [
                'component' => 'EventOutboxRelay',
                'event_class' => $eventClass,
                'row_id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return 'failed';
        }

        // schema_version is null only for legacy rows written before SV-1.
        // Anything numeric and greater than the writer's current version is
        // a forward-incompat row this binary is too old to dispatch.
        $schemaVersion = $decoded['schema_version'];
        if ($schemaVersion !== null && $schemaVersion > EventOutboxWriter::SCHEMA_VERSION) {
            $this->markUnsupportedSchema($id, $schemaVersion);
            $this->logger->warning('Outbox row has unsupported schema_version', [
                'component' => 'EventOutboxRelay',
                'event_class' => $eventClass,
                'row_id' => $id,
                'row_schema_version' => $schemaVersion,
                'supported_schema_version' => EventOutboxWriter::SCHEMA_VERSION,
            ]);
            return 'failed';
        }

        // Envelopes carry the canonical event_class; trust it over the
        // outbox column so a rename can be remediated by rewriting the
        // envelope without an UPDATE on `event_class`.
        $effectiveClass = $decoded['event_class'] ?? $eventClass;

        try {
            $event = $this->rehydrate($effectiveClass, $decoded['body']);
        } catch (\Throwable $e) {
            $this->markFailed($id, sprintf('rehydrate failed: %s', $e->getMessage()));
            $this->logger->error('Outbox event rehydrate failed', [
                'component' => 'EventOutboxRelay',
                'event_class' => $effectiveClass,
                'row_id' => $id,
                'exception' => $e->getMessage(),
            ]);
            return 'failed';
        }

        try {
            $this->dispatcher->dispatch($event);
        } catch (\Throwable $e) {
            return $this->onDispatchFailure($id, (int) ($row['attempts'] ?? 0), $e);
        }

        $this->markDelivered($id);
        return 'delivered';
    }

    /**
     * Parse the `payload` column into a normalised, version-aware shape.
     *
     * Returns the inner event body (`body`) regardless of whether the row
     * was written under the v1 envelope or the pre-SV-1 legacy shape, plus
     * the envelope metadata when present.
     *
     * @param string $json
     * @return array{schema_version: int|null, event_class: string|null, body: array<string, mixed>}
     * @throws \JsonException If the column is not valid JSON.
     */
    private function decodeEnvelope(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf(
                'Outbox payload must decode to an array, got %s',
                get_debug_type($decoded)
            ));
        }

        // Legacy shape (pre-SV-1): the column IS the event body. Detect by
        // absence of the schema_version marker. We deliberately do NOT
        // sniff for "payload" key alone, because an event could legally
        // have a property literally called "payload".
        if (!array_key_exists('schema_version', $decoded)) {
            /** @var array<string, mixed> $legacyBody */
            $legacyBody = $decoded;
            return [
                'schema_version' => null,
                'event_class' => null,
                'body' => $legacyBody,
            ];
        }

        $version = $decoded['schema_version'];
        if (!is_int($version)) {
            throw new \RuntimeException(sprintf(
                'Envelope schema_version must be an int, got %s',
                get_debug_type($version)
            ));
        }

        $eventClass = $decoded['event_class'] ?? null;
        if ($eventClass !== null && !is_string($eventClass)) {
            throw new \RuntimeException(sprintf(
                'Envelope event_class must be a string, got %s',
                get_debug_type($eventClass)
            ));
        }

        $rawBody = $decoded['payload'] ?? [];
        if (!is_array($rawBody)) {
            throw new \RuntimeException(sprintf(
                'Envelope payload must be an array, got %s',
                get_debug_type($rawBody)
            ));
        }

        /** @var array<string, mixed> $body */
        $body = $rawBody;
        return [
            'schema_version' => $version,
            'event_class' => $eventClass,
            'body' => $body,
        ];
    }

    /**
     * claim.
     *
     * @param int $id
     * @return bool
     */
    private function claim(int $id): bool
    {
        // CI4's update() returns `true` on success regardless of how many
        // rows matched, so a second worker that issued the SAME UPDATE a
        // millisecond later would also get `true` — admitting a double
        // dispatch. The authoritative signal is the driver-level row count.
        // We MUST gate on `affectedRows() === 1` and ignore the update()
        // return value entirely.
        $db = $this->connection();
        $db->table('event_outbox')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update(['status' => 'in_flight']);

        return $db->affectedRows() === 1;
    }

    /**
     * Rehydrate a domain event from its decoded body.
     *
     * @param string               $eventClass
     * @param array<string, mixed> $payload    Inner event body
     *                                         (already unwrapped from the
     *                                         envelope by
     *                                         {@see self::decodeEnvelope()}).
     * @return object
     * @throws \RuntimeException
     */
    private function rehydrate(string $eventClass, array $payload): object
    {
        if (!class_exists($eventClass)) {
            throw new \RuntimeException(sprintf('Event class %s no longer exists', $eventClass));
        }

        // SECURITY: only reflect over classes that explicitly mark
        // themselves as domain events. Without this, a row with a
        // hostile `event_class` (e.g. some installer/CLI class with a
        // side-effecting constructor) would be instantiated by the relay.
        $reflection = new \ReflectionClass($eventClass);
        if (!$reflection->implementsInterface(\App\Domain\Shared\Events\DomainEventInterface::class)) {
            throw new \RuntimeException(sprintf(
                'Refusing to rehydrate %s — does not implement DomainEventInterface',
                $eventClass
            ));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $payload)) {
                $args[] = $payload[$name];
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Payload for %s missing required parameter "%s"',
                $eventClass,
                $name
            ));
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * onDispatchFailure.
     *
     * @param int        $id
     * @param int        $currentAttempts
     * @param \Throwable $e
     * @return string
     */
    private function onDispatchFailure(int $id, int $currentAttempts, \Throwable $e): string
    {
        $nextAttempt = $currentAttempts + 1;
        $maxed = $nextAttempt >= self::MAX_ATTEMPTS;

        $update = [
            'status' => $maxed ? 'failed' : 'pending',
            'attempts' => $nextAttempt,
            'last_error' => $e->getMessage(),
        ];

        if (!$maxed) {
            $backoff = self::BACKOFF_SECONDS[min($currentAttempts, count(self::BACKOFF_SECONDS) - 1)];
            $available = (new \DateTimeImmutable())->modify('+' . $backoff . ' seconds');
            $update['available_at'] = $available->format('Y-m-d H:i:s');
        }

        $this->connection()->table('event_outbox')
            ->where('id', $id)
            ->update($update);

        $this->logger->warning('Outbox event dispatch failed', [
            'component' => 'EventOutboxRelay',
            'row_id' => $id,
            'attempts' => $nextAttempt,
            'will_retry' => !$maxed,
            'exception' => $e->getMessage(),
        ]);

        return $maxed ? 'failed' : 'retried';
    }

    /**
     * markDelivered.
     *
     * @param int $id
     * @return void
     */
    private function markDelivered(int $id): void
    {
        $this->connection()->table('event_outbox')
            ->where('id', $id)
            ->update([
                'status' => 'delivered',
                'delivered_at' => date('Y-m-d H:i:s'),
                'last_error' => null,
            ]);
    }

    /**
     * markFailed.
     *
     * @param int    $id
     * @param string $reason
     * @return void
     */
    private function markFailed(int $id, string $reason): void
    {
        $this->connection()->table('event_outbox')
            ->where('id', $id)
            ->update([
                'status' => 'failed',
                'last_error' => $reason,
            ]);
    }

    /**
     * Terminal mark for rows whose envelope schema_version this relay does
     * not understand (writer is ahead of relay). Distinct from `failed` so
     * operators can audit/replay them after upgrading the relay binary.
     *
     * @param int $id
     * @param int $rowSchemaVersion
     * @return void
     */
    private function markUnsupportedSchema(int $id, int $rowSchemaVersion): void
    {
        $this->connection()->table('event_outbox')
            ->where('id', $id)
            ->update([
                'status' => 'unsupported_schema',
                'last_error' => sprintf(
                    'schema_version %d is not supported by this relay (max supported: %d)',
                    $rowSchemaVersion,
                    EventOutboxWriter::SCHEMA_VERSION
                ),
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

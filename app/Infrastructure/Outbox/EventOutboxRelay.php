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
 */
final class EventOutboxRelay
{
    private const int MAX_ATTEMPTS = 6;
    private const array BACKOFF_SECONDS = [30, 120, 600, 3600, 21600, 86400];

    /**
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
            $event = $this->rehydrate($eventClass, (string) ($row['payload'] ?? '[]'));
        } catch (\Throwable $e) {
            $this->markFailed($id, sprintf('rehydrate failed: %s', $e->getMessage()));
            $this->logger->error('Outbox event rehydrate failed', [
                'component' => 'EventOutboxRelay',
                'event_class' => $eventClass,
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

    private function rehydrate(string $eventClass, string $json): object
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

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

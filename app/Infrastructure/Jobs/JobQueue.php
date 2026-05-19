<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Producer-side API for the database-backed job queue (D6).
 *
 * Typical use from a command handler:
 *
 *     $queue->push(
 *         handler: SendInvoiceEmailJob::class,
 *         payload: ['invoice_id' => 42, 'recipient' => 'a@b.c'],
 *     );
 *
 * The push runs inside the surrounding bus transaction so the job row
 * commits with the business write. Delayed jobs:
 *
 *     $queue->push(
 *         handler: ChargeCard::class,
 *         payload: [...],
 *         delaySeconds: 60 * 60 * 24 * 7,  // a week from now
 *     );
 */
final class JobQueue
{
    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * @param class-string<JobHandlerInterface> $handler
     * @param array<string, mixed> $payload
     */
    public function push(
        string $handler,
        array $payload,
        string $queue = 'default',
        int $delaySeconds = 0,
        int $maxAttempts = 5
    ): int {
        if ($delaySeconds < 0) {
            throw new \InvalidArgumentException('delaySeconds must be >= 0');
        }
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }

        $now = new \DateTimeImmutable();
        $availableAt = $delaySeconds > 0
            ? $now->modify('+' . $delaySeconds . ' seconds')
            : $now;

        $this->connection()->table('jobs')->insert([
            'queue' => $queue,
            'handler_class' => $handler,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'available_at' => $availableAt->format('Y-m-d H:i:s'),
            'reserved_at' => null,
            'last_error' => null,
            'correlation_id' => CorrelationIdService::get(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection()->insertID();
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

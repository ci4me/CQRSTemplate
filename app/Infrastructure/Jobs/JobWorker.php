<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs;

use App\Infrastructure\Logging\CorrelationIdService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Consumer-side worker for the database-backed job queue (D6).
 *
 * Each call to {@see self::drain()} processes up to $batchSize pending
 * jobs. A worker process invokes it in a loop. Multiple workers can run
 * concurrently: claiming is atomic (UPDATE WHERE status='pending').
 *
 * Retry policy uses an exponential backoff schedule keyed by attempts:
 *   30s, 2 min, 10 min, 1 h, 6 h, 24 h
 * Jobs with attempts >= max_attempts are marked failed.
 *
 * The handler is resolved via reflection — the worker calls
 * `new $handler_class()`. Handlers MUST have a zero-argument constructor.
 * Use Services::* lookups inside the handler if you need wired dependencies.
 */
final class JobWorker
{
    private const array BACKOFF_SECONDS = [30, 120, 600, 3600, 21600, 86400];

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?BaseConnection $db = null
    ) {
    }

    /**
     * @return array{processed: int, succeeded: int, retried: int, failed: int}
     */
    public function drain(string $queue = 'default', int $batchSize = 10): array
    {
        $processed = 0;
        $succeeded = 0;
        $retried = 0;
        $failed = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($this->fetchPending($queue, $batchSize, $now) as $row) {
            $processed++;
            $result = $this->processRow($row);
            match ($result) {
                'succeeded' => $succeeded++,
                'retried' => $retried++,
                'failed' => $failed++,
                default => null,
            };
        }

        return [
            'processed' => $processed,
            'succeeded' => $succeeded,
            'retried' => $retried,
            'failed' => $failed,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPending(string $queue, int $batchSize, string $now): array
    {
        $result = $this->connection()
            ->table('jobs')
            ->where('queue', $queue)
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

        $originalCorrelation = (string) ($row['correlation_id'] ?? '');
        if ($originalCorrelation !== '') {
            CorrelationIdService::set($originalCorrelation);
        }

        if (!$this->claim($id)) {
            return 'retried';
        }

        $handlerClass = (string) ($row['handler_class'] ?? '');
        $payloadRaw = (string) ($row['payload'] ?? '[]');
        $attempts = (int) ($row['attempts'] ?? 0);
        $maxAttempts = (int) ($row['max_attempts'] ?? 5);

        try {
            $handler = $this->resolveHandler($handlerClass);
            /** @var array<string, mixed> $payload */
            $payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);

            $handler->handle($payload);
        } catch (\Throwable $e) {
            return $this->onFailure($id, $attempts, $maxAttempts, $e);
        }

        $this->markDone($id);
        return 'succeeded';
    }

    private function claim(int $id): bool
    {
        $this->connection()
            ->table('jobs')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status' => 'reserved',
                'reserved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return $this->connection()->affectedRows() === 1;
    }

    private function resolveHandler(string $class): JobHandlerInterface
    {
        if ($class === '' || !class_exists($class)) {
            throw new \RuntimeException(sprintf('Job handler class "%s" not found', $class));
        }

        $instance = new $class();
        if (!$instance instanceof JobHandlerInterface) {
            throw new \RuntimeException(sprintf(
                'Job handler "%s" must implement %s',
                $class,
                JobHandlerInterface::class
            ));
        }

        return $instance;
    }

    private function onFailure(int $id, int $attempts, int $maxAttempts, \Throwable $e): string
    {
        $nextAttempts = $attempts + 1;
        $exhausted = $nextAttempts >= $maxAttempts;
        $now = date('Y-m-d H:i:s');

        $update = [
            'attempts' => $nextAttempts,
            'last_error' => $e->getMessage(),
            'updated_at' => $now,
        ];

        if ($exhausted) {
            $update['status'] = 'failed';
        } else {
            $backoff = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS) - 1)];
            $update['status'] = 'pending';
            $update['available_at'] = (new \DateTimeImmutable())
                ->modify('+' . $backoff . ' seconds')
                ->format('Y-m-d H:i:s');
            $update['reserved_at'] = null;
        }

        $this->connection()->table('jobs')->where('id', $id)->update($update);

        $this->logger->warning('Job failed', [
            'component' => 'JobWorker',
            'job_id' => $id,
            'attempts' => $nextAttempts,
            'will_retry' => !$exhausted,
            'exception' => $e->getMessage(),
        ]);

        return $exhausted ? 'failed' : 'retried';
    }

    private function markDone(int $id): void
    {
        $this->connection()
            ->table('jobs')
            ->where('id', $id)
            ->update([
                'status' => 'done',
                'last_error' => null,
                'updated_at' => date('Y-m-d H:i:s'),
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

<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserById;

use App\Domain\User\Entities\User;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Config\Logging;
use Psr\Log\LoggerInterface;

final readonly class GetUserByIdHandler
{
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    public function handle(GetUserByIdQuery $query): ?User
    {
        $startTime = microtime(true);
        $user = $this->repository->findById($query->id);
        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->logQueryExecution($query->id, $user, $durationMs);

        return $user;
    }

    private function logQueryExecution(int $id, ?User $result, float $durationMs): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs;

        if ($isSlowQuery) {
            $this->logQuery($id, $result, $durationMs, true);
            return;
        }

        $shouldLog = match ($this->loggingConfig->queryLoggingLevel) {
            'all' => true,
            'errors' => $result === null,
            'slow' => false,
            'sampling' => mt_rand() / mt_getrandmax() < $this->loggingConfig->samplingRate,
            default => false,
        };

        if (!$shouldLog) {
            return;
        }

        $this->logQuery($id, $result, $durationMs, false);
    }

    private function logQuery(int $id, ?User $result, float $durationMs, bool $isSlowQuery): void
    {
        $context = [
            'domain' => 'User',
            'query' => 'GetUserByIdQuery',
            'user_id' => $id,
            'result' => $result === null ? 'not_found' : 'found',
            'duration_ms' => round($durationMs, 2),
        ];

        if ($isSlowQuery) {
            $context['slow_query'] = true;
        }

        $this->logger->info('Query executed', $context);
    }
}

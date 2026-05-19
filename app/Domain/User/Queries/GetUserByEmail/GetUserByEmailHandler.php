<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserByEmail;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Config\Logging;
use Psr\Log\LoggerInterface;

final readonly class GetUserByEmailHandler
{
    public function __construct(
        private UserRepository $repository,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    public function handle(GetUserByEmailQuery $query): ?User
    {
        $startTime = microtime(true);

        $email = Email::fromString($query->email);
        $user = $this->repository->findByEmail($email);

        $durationMs = (microtime(true) - $startTime) * 1000;
        $this->logQueryExecution($email->getValue(), $user, $durationMs);

        return $user;
    }

    private function logQueryExecution(string $email, ?User $result, float $durationMs): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs;

        if ($isSlowQuery) {
            $this->logQuery($email, $result, $durationMs, true);
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

        $this->logQuery($email, $result, $durationMs, false);
    }

    private function logQuery(string $email, ?User $result, float $durationMs, bool $isSlowQuery): void
    {
        $context = [
            'domain' => 'User',
            'query' => 'GetUserByEmailQuery',
            'email' => $email,
            'result' => $result === null ? 'not_found' : 'found',
            'duration_ms' => round($durationMs, 2),
        ];

        if ($isSlowQuery) {
            $context['slow_query'] = true;
        }

        $this->logger->info('Query executed', $context);
    }
}

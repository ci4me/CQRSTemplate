<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserById;

use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Entities\User;
use App\Domain\User\Ports\UserRepositoryInterface;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * GetUserByIdHandler.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
final readonly class GetUserByIdHandler
{
    /**
     * __construct.
     *
     * @param UserRepositoryInterface $repository
     * @param LoggerInterface         $logger
     * @param Logging                 $loggingConfig
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    /**
     * handle.
     *
     * @param GetUserByIdQuery $query
     * @return UserDTO|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function handle(GetUserByIdQuery $query): ?UserDTO
    {
        $startTime = microtime(true);
        $user = $this->repository->findById($query->id);
        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->logQueryExecution($query->id, $user, $durationMs);

        return $user !== null ? UserDTO::fromEntity($user) : null;
    }

    /**
     * logQueryExecution.
     *
     * @param int       $id
     * @param User|null $result
     * @param float     $durationMs
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * logQuery.
     *
     * @param int       $id
     * @param User|null $result
     * @param float     $durationMs
     * @param bool      $isSlowQuery
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

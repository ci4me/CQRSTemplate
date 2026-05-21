<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetUserByEmail;

use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Entities\User;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\ValueObjects\Email;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * GetUserByEmailHandler.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
final readonly class GetUserByEmailHandler
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
     * @param GetUserByEmailQuery $query
     * @return UserDTO|null
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function handle(GetUserByEmailQuery $query): ?UserDTO
    {
        $startTime = microtime(true);

        $email = Email::fromString($query->email);
        $user = $this->repository->findByEmail($email);

        $durationMs = (microtime(true) - $startTime) * 1000;
        $this->logQueryExecution($email->getValue(), $user, $durationMs);

        return $user !== null ? UserDTO::fromEntity($user) : null;
    }

    /**
     * logQueryExecution.
     *
     * @param string    $email
     * @param User|null $result
     * @param float     $durationMs
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

    /**
     * logQuery.
     *
     * @param string    $email
     * @param User|null $result
     * @param float     $durationMs
     * @param bool      $isSlowQuery
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
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

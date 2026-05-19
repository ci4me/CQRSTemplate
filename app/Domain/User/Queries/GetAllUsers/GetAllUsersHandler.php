<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetAllUsers;

use App\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * Handler for GetAllUsersQuery.
 *
 * This handler retrieves a paginated list of users with optional filtering.
 * For advanced filtering, use SearchUsersHandler instead.
 *
 * Query Features:
 * - Pagination with configurable page size
 * - Optional search by email
 * - Include/exclude soft-deleted users
 * - Performance logging for slow queries
 *
 * @package App\Domain\User\Queries\GetAllUsers
 */
final readonly class GetAllUsersHandler
{
    public function __construct(
        private UserRepositoryInterface $repository,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    /**
     * @return array{data: array<\App\Domain\User\Entities\User>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function handle(GetAllUsersQuery $query): array
    {

        $startTime = microtime(true);

        $this->logger->info('Fetching paginated users', [
            'domain' => 'User',
            'query' => 'GetAllUsersQuery',
            'page' => $query->page,
            'perPage' => $query->perPage,
            'includeInactive' => $query->includeInactive,
            'searchTerm' => $query->searchTerm,
        ]);

        try {
            $result = $this->repository->findPaginated(
                page: $query->page,
                perPage: $query->perPage,
                includeInactive: $query->includeInactive,
                searchTerm: $query->searchTerm
            );

            $duration = (microtime(true) - $startTime) * 1000;

            if ($duration > $this->loggingConfig->slowQueryThresholdMs) {
                $this->logger->warning('Slow query detected', [
                    'domain' => 'User',
                    'query' => 'GetAllUsersQuery',
                    'duration_ms' => round($duration, 2),
                    'total_results' => $result['total'],
                ]);
            } else {
                $this->logger->info('Users fetched successfully', [
                    'domain' => 'User',
                    'query' => 'GetAllUsersQuery',
                    'duration_ms' => round($duration, 2),
                    'total_results' => $result['total'],
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch users', [
                'domain' => 'User',
                'query' => 'GetAllUsersQuery',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }
}

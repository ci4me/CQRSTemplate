<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\GetAllUsers;

use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Ports\UserRepositoryInterface;
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
     * @param GetAllUsersQuery $query
     * @return array{data: array<UserDTO>, total: int, page: int, perPage: int, lastPage: int}
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

            $result['data'] = array_map(static fn($user) => UserDTO::fromEntity($user), $result['data']);

            $result['lastPage'] = $result['totalPages'];
            unset($result['totalPages']);

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

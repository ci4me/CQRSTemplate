<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\SearchUsers;

use App\Domain\Shared\Bus\QueryHandlerInterface;
use App\Domain\Shared\Ports\LogConfigPort;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Ports\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for SearchUsersQuery.
 *
 * This handler executes advanced user searches with multiple filter criteria.
 * All filters are optional and can be combined for complex searches.
 *
 * Query Features:
 * - Filter by email (partial match)
 * - Filter by role (exact match)
 * - Filter by status (exact match)
 * - Pagination support
 * - Performance logging
 *
 * Use Cases:
 * - Admin dashboard user filtering
 * - User management search
 * - Reporting and analytics
 *
 * @package App\Domain\User\Queries\SearchUsers
 * @implements QueryHandlerInterface<SearchUsersQuery, array{data: array<UserDTO>, total: int, page: int, perPage: int, lastPage: int}>
 */
final readonly class SearchUsersHandler implements QueryHandlerInterface
{
    /**
     * __construct.
     */
    public function __construct(
        private UserRepositoryInterface $repository,
        private LoggerInterface $logger,
        private LogConfigPort $loggingConfig
    ) {
    }

    /**
     * @param SearchUsersQuery $query
     * @return array{data: array<UserDTO>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function handle(object $query): array
    {

        $startTime = microtime(true);

        $this->logger->info('Searching users with filters', [
            'domain' => 'User',
            'query' => 'SearchUsersQuery',
            'email' => $query->email,
            'role' => $query->role,
            'status' => $query->status,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ]);

        try {
            $result = $this->repository->findPaginated(
                page: $query->page,
                perPage: $query->perPage,
                includeInactive: false,
                searchTerm: $query->email ?? '',
                role: $query->role,
                status: $query->status
            );

            $duration = (microtime(true) - $startTime) * 1000;

            if ($duration > $this->loggingConfig->slowQueryThresholdMs()) {
                $this->logger->warning('Slow search query detected', [
                    'domain' => 'User',
                    'query' => 'SearchUsersQuery',
                    'duration_ms' => round($duration, 2),
                    'total_results' => $result['total'],
                ]);
            } else {
                $this->logger->info('User search completed', [
                    'domain' => 'User',
                    'query' => 'SearchUsersQuery',
                    'duration_ms' => round($duration, 2),
                    'total_results' => $result['total'],
                ]);
            }

            $result['data'] = array_map(static fn($user) => UserDTO::fromEntity($user), $result['data']);

            $result['lastPage'] = $result['totalPages'];
            unset($result['totalPages']);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('User search failed', [
                'domain' => 'User',
                'query' => 'SearchUsersQuery',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }
}

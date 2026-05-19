<?php

declare(strict_types=1);

namespace App\Domain\User\Queries\SearchUsers;

use App\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use Config\Logging;
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
 */
final readonly class SearchUsersHandler
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
    public function handle(SearchUsersQuery $query): array
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

            if ($duration > $this->loggingConfig->slowQueryThresholdMs) {
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

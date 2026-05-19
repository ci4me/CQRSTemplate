<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\User\Entities\User;
use App\Domain\User\ErrorCodes;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Persistence\Models\UserModel;
use Config\Logging;
use Psr\Log\LoggerInterface;

readonly class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private UserModel $model,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    public function save(User $user): int
    {
        $startTime = microtime(true);

        try {
            $data = $this->toArray($user);
            $id = $this->model->insert($data);

            if ($id === false) {
                throw new \RuntimeException('Failed to save user', ErrorCodes::USER_REPOSITORY_SAVE_FAILED);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('User saved', [
                'domain' => 'User',
                'repository' => 'UserRepository',
                'user_id' => $id,
                'duration_ms' => round($duration, 2),
            ]);

            return (int) $id;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save user', [
                'domain' => 'User',
                'exception' => $e->getMessage(),
                'error_code' => ErrorCodes::USER_REPOSITORY_SAVE_FAILED,
            ]);
            throw $e;
        }
    }

    public function findById(int $id): ?User
    {
        $startTime = microtime(true);

        try {
            $row = $this->model->find($id);
            $duration = (microtime(true) - $startTime) * 1000;

            if ($row === null) {
                $this->logQuery('findById', $duration, false);
                return null;
            }

            $this->logQuery('findById', $duration, true);
            return $this->toDomainEntity($row);
        } catch (\Throwable $e) {
            $this->logger->error('Query failed', [
                'domain' => 'User',
                'method' => 'findById',
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function findByEmail(Email $email): ?User
    {
        $startTime = microtime(true);

        try {
            $row = $this->model->where('email', $email->getValue())->first();
            $duration = (microtime(true) - $startTime) * 1000;

            if ($row === null) {
                $this->logQuery('findByEmail', $duration, false);
                return null;
            }

            $this->logQuery('findByEmail', $duration, true);
            return $this->toDomainEntity($row);
        } catch (\Throwable $e) {
            $this->logger->error('Query failed', [
                'domain' => 'User',
                'method' => 'findByEmail',
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function update(User $user): bool
    {
        $startTime = microtime(true);

        try {
            $data = $this->toArray($user);
            $result = $this->model->update($user->getId(), $data);

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logger->info('User updated', [
                'domain' => 'User',
                'user_id' => $user->getId(),
                'duration_ms' => round($duration, 2),
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update user', [
                'domain' => 'User',
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        try {
            return $this->model->delete($id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete user', [
                'domain' => 'User',
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Find users with pagination and optional filters.
     *
     * @param int $page Page number (1-based)
     * @param int $perPage Items per page
     * @param bool $includeInactive Include soft-deleted users
     * @param string $searchTerm Search in name and email
     * @param string|null $role Filter by role
     * @param string|null $status Filter by status
     * @return array{data: array<User>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function findPaginated(
        int $page,
        int $perPage,
        bool $includeInactive = false,
        string $searchTerm = '',
        ?string $role = null,
        ?string $status = null
    ): array {
        $startTime = microtime(true);

        try {
            $builder = $this->model->builder();

            // Apply filters
            if (!$includeInactive) {
                $builder->where('deleted_at IS NULL');
            }

            if ($searchTerm !== '') {
                $builder->groupStart()
                    ->like('name', $searchTerm)
                    ->orLike('email', $searchTerm)
                    ->groupEnd();
            }

            if ($role !== null) {
                $builder->where('role', $role);
            }

            if ($status !== null) {
                $builder->where('status', $status);
            }

            // Get total count
            $total = $builder->countAllResults(false);

            // Apply pagination
            $offset = ($page - 1) * $perPage;
            $builder->limit($perPage, $offset);
            $builder->orderBy('created_at', 'DESC');

            // Execute query
            $rows = $builder->get()->getResultArray();
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logQuery('findPaginated', $duration, count($rows) > 0);

            // Convert to entities
            $users = array_map([$this, 'toDomainEntity'], $rows);

            $totalPages = (int) ceil($total / $perPage);

            return [
                'data' => $users,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => $totalPages,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Paginated query failed', [
                'domain' => 'User',
                'method' => 'findPaginated',
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Count total users (excluding deleted).
     *
     * @return int Total user count
     */
    public function countTotal(): int
    {
        try {
            return $this->model->where('deleted_at IS NULL')->countAllResults();
        } catch (\Throwable $e) {
            $this->logger->error('Count query failed', [
                'domain' => 'User',
                'method' => 'countTotal',
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Count users by role.
     *
     * @param string $role Role to count (admin, customer)
     * @return int User count for role
     */
    public function countByRole(string $role): int
    {
        try {
            return $this->model
                ->where('role', $role)
                ->where('deleted_at IS NULL')
                ->countAllResults();
        } catch (\Throwable $e) {
            $this->logger->error('Count by role failed', [
                'domain' => 'User',
                'method' => 'countByRole',
                'role' => $role,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Count users by status.
     *
     * @param string $status Status to count (active, inactive)
     * @return int User count for status
     */
    public function countByStatus(string $status): int
    {
        try {
            return $this->model
                ->where('status', $status)
                ->where('deleted_at IS NULL')
                ->countAllResults();
        } catch (\Throwable $e) {
            $this->logger->error('Count by status failed', [
                'domain' => 'User',
                'method' => 'countByStatus',
                'status' => $status,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function toDomainEntity(array $row): User
    {
        return User::reconstitute(
            id: (int) $row['id'],
            name: UserName::fromString($row['name']),
            email: Email::fromString($row['email']),
            hashedPassword: HashedPassword::fromHash($row['password_hash']),
            role: UserRole::from($row['role']),
            status: UserStatus::from($row['status']),
            failedLoginAttempts: (int) $row['failed_login_attempts'],
            lockedUntil: $row['locked_until'] ? new \DateTimeImmutable($row['locked_until']) : null,
            createdAt: new \DateTimeImmutable($row['created_at']),
            updatedAt: $row['updated_at'] ? new \DateTimeImmutable($row['updated_at']) : null,
            deletedAt: $row['deleted_at'] ? new \DateTimeImmutable($row['deleted_at']) : null
        );
    }

    private function toArray(User $user): array
    {
        return [
            'name' => $user->getName()->getValue(),
            'email' => $user->getEmail()->getValue(),
            'password_hash' => $user->getHashedPassword()->getHash(),
            'role' => $user->getRole()->value,
            'status' => $user->getStatus()->value,
            'failed_login_attempts' => $user->getFailedLoginAttempts(),
            'locked_until' => $user->getLockedUntil()?->format('Y-m-d H:i:s'),
        ];
    }

    private function logQuery(string $method, float $durationMs, bool $found): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs;

        if ($isSlowQuery) {
            $this->logger->warning('Slow query detected', [
                'domain' => 'User',
                'method' => $method,
                'duration_ms' => round($durationMs, 2),
                'found' => $found,
            ]);
        }
    }
}

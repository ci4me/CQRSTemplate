<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use App\Domain\User\Entities\User;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Ports\UserRepositoryInterface;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Attributes\AutoBind;
use App\Infrastructure\Attributes\InfrastructureAdapter;
use App\Infrastructure\Persistence\Models\UserModel;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * UserRepository.
 *
 * NOTE: Not final to allow mocking in unit tests (pragmatic exception to "final by default" rule)
 */
#[InfrastructureAdapter]
#[AutoBind]
readonly class UserRepository implements UserRepositoryInterface
{
    /**
     * __construct.
     *
     * @param UserModel       $model
     * @param LoggerInterface $logger
     * @param Logging         $loggingConfig
     */
    public function __construct(
        private UserModel $model,
        private LoggerInterface $logger,
        private Logging $loggingConfig
    ) {
    }

    /**
     * save.
     *
     * @param User $user
     * @return int
     * @throws \RuntimeException
     */
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

    /**
     * findById.
     *
     * @param int $id
     * @return User|null
     */
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
            if (!is_array($row)) {
                return null;
            }
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

    /**
     * findByEmail.
     *
     * @param Email $email
     * @return User|null
     */
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
            if (!is_array($row)) {
                return null;
            }
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

    /**
     * update.
     *
     * @param User $user
     * @return bool
     */
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

    /**
     * delete.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        try {
            $result = $this->model->delete($id);
            // Model::delete returns BaseResult|bool; coerce so the
            // declared contract holds.
            return $result === true;
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
     * @param int         $page            Page number (1-based)
     * @param int         $perPage         Items per page
     * @param bool        $includeInactive Include soft-deleted users
     * @param string      $searchTerm      Search in name and email
     * @param string|null $role            Filter by role
     * @param string|null $status          Filter by status
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

            // Get total count. countAllResults is typed int|string upstream;
            // cast once for the math.
            $total = (int) $builder->countAllResults(false);

            // Apply pagination
            $offset = ($page - 1) * $perPage;
            $builder->limit($perPage, $offset);
            $builder->orderBy('created_at', 'DESC');

            // Execute query. get() may return false on driver failure.
            $result = $builder->get();
            $rows = $result === false ? [] : $result->getResultArray();
            $duration = (microtime(true) - $startTime) * 1000;

            $this->logQuery('findPaginated', $duration, count($rows) > 0);

            // Convert to entities
            $users = array_map([$this, 'toDomainEntity'], $rows);

            $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

            return [
                'data' => $users,
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => max(1, $totalPages),
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
            return (int) $this->model->where('deleted_at IS NULL')->countAllResults();
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
            return (int) $this->model
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
            return (int) $this->model
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

    /**
     * @param array<int|string, mixed> $row Raw database row from CI4 Model::find / first / getResultArray
     * @return User
     */
    private function toDomainEntity(array $row): User
    {
        return User::reconstitute(
            id: (int) $row['id'],
            name: UserName::fromString((string) $row['name']),
            email: Email::fromString((string) $row['email']),
            hashedPassword: HashedPassword::fromHash((string) $row['password_hash']),
            role: UserRole::from((string) $row['role']),
            status: UserStatus::from((string) $row['status']),
            failedLoginAttempts: (int) $row['failed_login_attempts'],
            lockedUntil: isset($row['locked_until']) && $row['locked_until'] !== ''
                ? new \DateTimeImmutable((string) $row['locked_until']) : null,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            updatedAt: isset($row['updated_at']) && $row['updated_at'] !== ''
                ? new \DateTimeImmutable((string) $row['updated_at']) : null,
            deletedAt: isset($row['deleted_at']) && $row['deleted_at'] !== ''
                ? new \DateTimeImmutable((string) $row['deleted_at']) : null
        );
    }

    /**
     * @param User $user
     * @return array<string, scalar|null>
     */
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

    /**
     * logQuery.
     *
     * @param string $method
     * @param float  $durationMs
     * @param bool   $found
     * @return void
     */
    private function logQuery(string $method, float $durationMs, bool $found): void
    {
        $isSlowQuery = $durationMs > $this->loggingConfig->slowQueryThresholdMs;

        if (!$isSlowQuery) {
            return;
        }

        $this->logger->warning('Slow query detected', [
            'domain' => 'User',
            'method' => $method,
            'duration_ms' => round($durationMs, 2),
            'found' => $found,
        ]);
    }
}

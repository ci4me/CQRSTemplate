<?php

declare(strict_types=1);

namespace App\Models\Cookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Cookie\Traits\BusinessMetricsLogging;
use App\Models\Cookie\Traits\RepositoryLogging;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Logging;
use Psr\Log\LoggerInterface;

/**
 * Repository for Cookie persistence.
 *
 * The Repository pattern provides:
 * - Abstraction over data access
 * - Domain entities instead of raw database arrays
 * - Collection-like interface for the domain
 * - Separation between domain and infrastructure
 *
 * Responsibilities:
 * - Save/update domain entities to database
 * - Load data from database as domain entities
 * - Provide query methods for the domain
 * - Handle mapping between database and domain
 *
 * Why Repository Pattern:
 * - Domain doesn't know about database details
 * - Easy to swap persistence implementation
 * - Centralizes data access logic
 * - Makes testing easier (can mock repository)
 *
 * @package App\Models\Cookie
 */
class CookieRepository implements CookieRepositoryInterface
{
    use BusinessMetricsLogging;
    use RepositoryLogging;

    private CookieModel $model;

    /**
     * Logger instance for repository operations.
     * Used by RepositoryLogging and BusinessMetricsLogging traits.
     */
    private LoggerInterface $logger;

    /**
     * Logging configuration.
     * Used by RepositoryLogging and BusinessMetricsLogging traits.
     */
    private Logging $loggingConfig;

    /**
     * Create a new CookieRepository.
     */
    public function __construct(
        LoggerInterface $logger,
        Logging $loggingConfig,
        ?CookieModel $model = null
    ) {
        $this->model = $model ?? new CookieModel();
        $this->logger = $logger;
        $this->loggingConfig = $loggingConfig;
    }

    /**
     * Save a cookie (create or update).
     *
     * @param Cookie $cookie The cookie to save
     * @return int The cookie ID
     */
    public function save(Cookie $cookie): int
    {
        try {
            $oldPrice = $this->getOldPrice($cookie);
            $cookieId = $this->performSave($cookie);
            $this->logBusinessMetrics($cookie, $cookieId, $oldPrice);

            return $cookieId;
        } catch (DatabaseException $e) {
            // B6: translate duplicate-key SQL errors into a domain-level
            // violation. Concurrent creates race past the existsByName check
            // in the handler; the DB unique index catches them and we map the
            // result to a stable error code instead of leaking SQL state.
            if ($this->isDuplicateKey($e)) {
                $this->logSaveError($e);
                throw DomainException::businessRuleViolation(
                    'Cookie name must be unique within the tenant.',
                    $cookie->getName()->getValue(),
                    ErrorCodes::COOKIE_VALIDATION_NAME
                );
            }

            $this->logSaveError($e);
            throw $e;
        } catch (\Throwable $e) {
            $this->logSaveError($e);
            throw $e;
        }
    }

    private function isDuplicateKey(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, '1062');
    }

    /**
     * Find a cookie by ID.
     *
     * @param int $id The cookie ID
     * @return Cookie|null The cookie or null if not found
     */
    public function findById(int $id): ?Cookie
    {
        try {
            /** @var array<int|string, bool|float|int|string|null>|object|null $data */
            $data = $this->model->find($id);

            if (!is_array($data)) {
                return null;
            }

            $cookie = $this->toDomainEntity($data);
            $this->trackPopularCookie($id);

            return $cookie;
        } catch (\Throwable $e) {
            $this->logQueryError('findById', $e);
            throw $e;
        }
    }

    /**
     * Find all cookies.
     *
     * @param bool $includeInactive Whether to include inactive cookies
     * @return array<int, Cookie> Array of cookies
     */
    public function findAll(bool $includeInactive = false): array
    {
        try {
            $startTime = microtime(true);
            $cookies = $this->executeFindAll($includeInactive);
            $this->logSlowQuery('findAll', $startTime, count($cookies), ['includeInactive' => $includeInactive]);

            return $cookies;
        } catch (\Throwable $e) {
            $this->logQueryError('findAll', $e);
            throw $e;
        }
    }

    /**
     * Find cookies with pagination and optional search.
     *
     * @param int $page The page number (1-indexed)
     * @param int $perPage Number of items per page
     * @param string|null $searchTerm Optional search term
     * @param bool $includeInactive Whether to include inactive cookies
     * @return array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ): array {
        try {
            $startTime = microtime(true);
            $result = $this->executeFindPaginated($page, $perPage, $searchTerm, $includeInactive);
            $this->logSlowPaginatedQuery('findPaginated', $startTime, $result, $page, $perPage, $searchTerm);

            return $result;
        } catch (\Throwable $e) {
            $this->logQueryError('findPaginated', $e);
            throw $e;
        }
    }

    /**
     * Check if a cookie exists with the given name.
     *
     * @param string $name The cookie name
     * @return bool True if exists
     */
    public function existsByName(string $name): bool
    {
        return $this->model->existsByName($name);
    }

    /**
     * Check if a cookie exists with the given name, excluding a specific ID.
     *
     * @param string $name The cookie name
     * @param int $excludeId The ID to exclude
     * @return bool True if exists
     */
    public function existsByNameExcludingId(string $name, int $excludeId): bool
    {
        return $this->model->existsByNameExcludingId($name, $excludeId);
    }

    /**
     * Soft delete a cookie.
     *
     * @param int $id The cookie ID
     * @return bool True if successful, false if cookie doesn't exist
     */
    public function delete(int $id): bool
    {
        try {
            $cookie = $this->findById($id);
            if ($cookie === null) {
                return false;
            }

            $result = $this->model->delete($id);

            return is_bool($result) ? $result : false;
        } catch (\Throwable $e) {
            $this->logDeleteError($e);
            throw $e;
        }
    }

    /**
     * Restore a previously soft-deleted cookie.
     */
    public function restore(int $id): bool
    {
        try {
            $cookie = $this->findByIdWithTrashed($id);
            if ($cookie === null || !$cookie->isDeleted()) {
                return false;
            }

            return $this->model->builder()
                ->where('id', $id)
                ->update(['deleted_at' => null]);
        } catch (\Throwable $e) {
            $this->logDeleteError($e);
            throw $e;
        }
    }

    /**
     * Find a cookie by id, ignoring the soft-delete filter.
     */
    public function findByIdWithTrashed(int $id): ?Cookie
    {
        /** @var array<int|string, bool|float|int|string|null>|object|null $row */
        $row = $this->model->withDeleted()->find($id);

        if (!is_array($row)) {
            return null;
        }

        return $this->toDomainEntity($row);
    }

    /**
     * Convert database array to domain entity.
     *
     * @param array<int|string, bool|float|int|string|null> $data The database row
     * @return Cookie The domain entity
     */
    private function toDomainEntity(array $data): Cookie
    {
        return Cookie::reconstitute(
            id: (int) $data['id'],
            name: CookieName::fromString((string) $data['name']),
            description: is_string($data['description']) ? $data['description'] : null,
            price: CookiePrice::fromString((string) $data['price']),
            stock: (int) $data['stock'],
            isActive: (bool) $data['is_active'],
            createdAt: is_string($data['created_at']) ? $data['created_at'] : null,
            updatedAt: is_string($data['updated_at']) ? $data['updated_at'] : null,
            deletedAt: isset($data['deleted_at']) && is_string($data['deleted_at']) ? $data['deleted_at'] : null,
            version: isset($data['version']) ? (int) $data['version'] : 0
        );
    }

    /**
     * Get old price for existing cookie.
     */
    private function getOldPrice(Cookie $cookie): ?CookiePrice
    {
        if ($cookie->getId() === null) {
            return null;
        }

        $existing = $this->findById($cookie->getId());

        return $existing?->getPrice();
    }

    /**
     * Perform the actual save operation.
     *
     * For updates this enforces optimistic locking: the UPDATE is scoped to
     * `WHERE id = ? AND version = ?` and the version column is bumped in the
     * same statement. If zero rows are affected the row's version moved on
     * (someone else wrote concurrently) and we throw a domain-level
     * concurrent-modification exception.
     */
    private function performSave(Cookie $cookie): int
    {
        $data = [
            'name' => $cookie->getName()->getValue(),
            'description' => $cookie->getDescription(),
            'price' => $cookie->getPrice()->toDecimalString(),
            'stock' => $cookie->getStock(),
            'is_active' => $cookie->getIsActive() ? 1 : 0,
        ];

        $id = $cookie->getId();
        if ($id !== null) {
            $this->updateWithOptimisticLock($cookie, $data);
            $cookie->bumpVersion();

            return $id;
        }

        // First insert: row and entity start at version 1. Subsequent updates
        // bump in lock-step so DB and in-memory version always agree.
        $cookie->bumpVersion();
        $data['version'] = $cookie->getVersion();

        $newId = (int) $this->model->insert($data);
        // Hydrate the entity so subsequent saves take the UPDATE path and
        // optimistic locking applies.
        $cookie->assignId($newId);

        return $newId;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    private function updateWithOptimisticLock(Cookie $cookie, array $data): void
    {
        $expectedVersion = $cookie->getVersion();
        $now = date('Y-m-d H:i:s');
        $data['version'] = $expectedVersion + 1;
        $data['updated_at'] = $now;

        $builder = $this->model->builder();
        $builder->where('id', $cookie->getId())
            ->where('version', $expectedVersion)
            ->update($data);

        $affected = $this->model->db->affectedRows();

        if ($affected === 1) {
            return;
        }

        $this->raiseConcurrentModification($cookie, $expectedVersion);
    }

    private function raiseConcurrentModification(Cookie $cookie, int $expectedVersion): never
    {
        /** @var array<string, scalar|null>|object|null $current */
        $current = $this->model->find($cookie->getId());
        $actual = -1;
        if (is_array($current) && isset($current['version'])) {
            $actual = (int) $current['version'];
        }

        throw DomainException::concurrentModification(
            'Cookie',
            (string) $cookie->getId(),
            $expectedVersion,
            $actual,
            ErrorCodes::COOKIE_STATE_CONCURRENT_MODIFICATION
        );
    }


    /**
     * Execute findAll query.
     *
     * @return array<int, Cookie>
     */
    private function executeFindAll(bool $includeInactive): array
    {
        $builder = $this->model->builder();
        $builder->where('deleted_at IS NULL');

        if (!$includeInactive) {
            $builder->where('is_active', 1);
        }

        $result = $builder->get();
        if ($result === false) {
            throw new \RuntimeException('Cookie findAll query failed');
        }

        $results = $result->getResultArray();

        return array_map(fn(array $data): Cookie => $this->toDomainEntity($data), $results);
    }


    /**
     * Execute findPaginated query.
     *
     * @return array{data: array<int, Cookie>, total: int, page: int, perPage: int, lastPage: int}
     */
    private function executeFindPaginated(
        int $page,
        int $perPage,
        ?string $searchTerm,
        bool $includeInactive
    ): array {
        $builder = $this->model->builder();
        $builder->where('deleted_at IS NULL');

        if (!$includeInactive) {
            $builder->where('is_active', 1);
        }

        if ($searchTerm !== null && $searchTerm !== '') {
            $builder->like('name', $searchTerm);
        }

        $totalCount = $builder->countAllResults(false);
        if (!is_int($totalCount)) {
            throw new \RuntimeException('Cookie findPaginated count query failed');
        }

        $total = $totalCount;
        $offset = ($page - 1) * $perPage;
        $lastPage = (int) ceil($total / $perPage);

        $result = $builder
            ->limit($perPage, $offset)
            ->orderBy('created_at', 'DESC')
            ->get();

        if ($result === false) {
            throw new \RuntimeException('Cookie findPaginated query failed');
        }

        $results = $result->getResultArray();
        $cookies = array_map(fn(array $data): Cookie => $this->toDomainEntity($data), $results);

        return [
            'data' => $cookies,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, $lastPage),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Repositories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Aggregate\AggregateHydrator;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Attributes\AutoBind;
use App\Infrastructure\Attributes\InfrastructureAdapter;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Outbox\EventOutboxWriter;
use App\Models\Cookie\CookieModel;
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
#[InfrastructureAdapter]
#[AutoBind]
final class CookieRepository implements CookieRepositoryInterface
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

    private ?EventDispatcher $eventDispatcher;
    private ?EventOutboxWriter $outboxWriter;
    private ?\App\Infrastructure\Tenancy\TenantContext $tenantContext;

    /**
     * Create a new CookieRepository.
     *
     * The optional EventOutboxWriter records every drained event in the
     * `event_outbox` table inside the same DB transaction as the entity
     * write (TransactionMiddleware wraps the whole command pipeline).
     * That gives us a durable audit trail and a retry surface — the
     * relay picks rows up out-of-band when synchronous dispatch failed.
     *
     * The optional TenantContext stamps `tenant_id` on every insert and
     * scopes queries; the column is part of the composite UNIQUE so the
     * default fallback (1) is what keeps the index meaningful on
     * single-tenant deploys.
     */
    public function __construct(
        LoggerInterface $logger,
        Logging $loggingConfig,
        ?CookieModel $model = null,
        ?EventDispatcher $eventDispatcher = null,
        ?EventOutboxWriter $outboxWriter = null,
        ?\App\Infrastructure\Tenancy\TenantContext $tenantContext = null
    ) {
        $this->model = $model ?? new CookieModel();
        $this->logger = $logger;
        $this->loggingConfig = $loggingConfig;
        $this->eventDispatcher = $eventDispatcher;
        $this->outboxWriter = $outboxWriter;
        $this->tenantContext = $tenantContext;
    }

    /**
     * Save a cookie (create or update).
     *
     * MUTATES `$cookie`:
     *   - On first insert, calls `assignId()` so the entity carries the
     *     generated row id.
     *   - On every successful persist, calls `bumpVersion()` so the
     *     in-memory entity stays in lock-step with the row's `version`
     *     column. The optimistic-locking guard in
     *     {@see self::updateWithOptimisticLock()} relies on this.
     *
     * The mutation is INTENTIONAL — callers must keep using the same
     * `Cookie` reference for subsequent operations (06/F13).
     *
     * @param Cookie     $cookie The cookie to save
     * @param Actor|null $actor  Stamps `created_by` (insert) / `updated_by` (update)
     * @return int The cookie ID
     * @throws DomainException
     */
    public function save(Cookie $cookie, ?Actor $actor = null): int
    {
        try {
            $oldPrice = $this->getOldPrice($cookie);
            $cookieId = $this->performSave($cookie, $actor);
            $this->logBusinessMetrics($cookie, $cookieId, $oldPrice);

            // C4: drain any events the aggregate accumulated during the
            // operation. Dispatching is the COMMAND HANDLER's responsibility
            // (clean separation: repository persists, handler orchestrates).
            // We still drain here to keep the buffer bounded if a caller
            // forgets — see CommandHandlers for the dispatch loop.
            $this->dispatchPendingEvents($cookie);

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

    /**
     * isDuplicateKey.
     */
    private function isDuplicateKey(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, '1062');
    }

    /**
     * dispatchPendingEvents.
     */
    private function dispatchPendingEvents(Cookie $cookie): void
    {
        $events = $cookie->pullEvents();
        if ($events === []) {
            return;
        }

        // Outbox FIRST so the event row commits with the entity write
        // even if synchronous dispatch fails. The relay drains pending
        // rows out-of-band and retries until a listener succeeds.
        if ($this->outboxWriter !== null) {
            $cookieId = $cookie->getId();
            foreach ($events as $event) {
                $this->outboxWriter->append($event, Cookie::class, $cookieId);
            }
        }

        if ($this->eventDispatcher === null) {
            // No dispatcher injected (typically in repository-only tests).
            // The outbox writer above is enough — the relay will deliver
            // the row when next drained. Drop on the floor here is safe.
            return;
        }

        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
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

            // Popularity tracking USED to live here (06/F12) — a read-side
            // concern leaked into the write-side adapter. The metric
            // belongs on the read repository if it lives anywhere; the
            // write path now reconstitutes and returns without side
            // effects.
            return $this->toDomainEntity($data);
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
     * @param int         $page            The page number (1-indexed)
     * @param int         $perPage         Number of items per page
     * @param string|null $searchTerm      Optional search term
     * @param bool        $includeInactive Whether to include inactive cookies
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
     * Scope intentionally EXCLUDES soft-deleted rows: the migration's
     * composite UNIQUE (`tenant_id`, `name`, `deleted_at`) treats NULL
     * deleted_at as the "live row" slot — a name that belongs to a
     * trashed cookie is available to be reused (closes 06/F1).
     */
    public function existsByName(CookieName $name): bool
    {
        return $this->model->existsByName($name->getValue());
    }

    /**
     * Check if a cookie exists with the given name, excluding a specific ID.
     *
     * Used by update handlers so a row can keep its own name.
     */
    public function existsByNameExcludingId(CookieName $name, int $excludeId): bool
    {
        return $this->model->existsByNameExcludingId($name->getValue(), $excludeId);
    }

    /**
     * Soft delete a cookie.
     *
     * Single conditional UPDATE — no SELECT-before-write, no second pass
     * for the audit columns. The WHERE clause includes
     * `deleted_at IS NULL` so a re-delete of an already-soft-deleted row
     * is a no-op that returns false rather than re-stamping the
     * deletion timestamp (closes 06/F8).
     *
     * @param int        $id    The cookie ID
     * @param Actor|null $actor Stamps `deleted_by` in the same UPDATE
     * @return bool True iff exactly one row was affected.
     */
    public function delete(int $id, ?Actor $actor = null): bool
    {
        try {
            $payload = [
                'deleted_at' => date('Y-m-d H:i:s'),
                'version' => new \CodeIgniter\Database\RawSql('version + 1'),
            ];
            if ($actor !== null) {
                $payload['deleted_by'] = $actor->id;
            }

            $this->model->builder()
                ->where('id', $id)
                ->where('deleted_at', null)
                ->update($payload);

            return $this->model->lastAffectedRows() === 1;
        } catch (\Throwable $e) {
            $this->logDeleteError($e);
            throw $e;
        }
    }

    /**
     * Restore a previously soft-deleted cookie.
     *
     * Conditional UPDATE scoped on `deleted_at IS NOT NULL` so a
     * restore of an active row is a no-op that returns false. The
     * version column is bumped in the same statement so any in-memory
     * `Cookie` carrying a stale version cannot silently overwrite the
     * restored row on a subsequent save (closes 06/F9 — optimistic-
     * locking gap).
     *
     * @param int        $id    The cookie ID
     * @param Actor|null $actor Stamps `updated_by` on the restored row
     * @return bool True iff exactly one row was affected.
     */
    public function restore(int $id, ?Actor $actor = null): bool
    {
        try {
            $update = [
                'deleted_at' => null,
                'deleted_by' => null,
                'version' => new \CodeIgniter\Database\RawSql('version + 1'),
            ];
            if ($actor !== null) {
                $update['updated_by'] = $actor->id;
                $update['updated_at'] = date('Y-m-d H:i:s');
            }

            $this->model->builder()
                ->where('id', $id)
                ->where('deleted_at IS NOT NULL', null, false)
                ->update($update);

            return $this->model->lastAffectedRows() === 1;
        } catch (\Throwable $e) {
            $this->logDeleteError($e);
            throw $e;
        }
    }

    /**
     * Hard-delete a cookie row.
     *
     * GDPR / right-to-erasure escape hatch — see
     * {@see CookieRepositoryInterface::purge()}. THIS IS DESTRUCTIVE and
     * there is no recovery once the statement commits. The normal
     * lifecycle is {@see self::delete()} (soft) + {@see self::restore()}.
     *
     * @return bool True iff exactly one row was affected.
     */
    public function purge(int $id): bool
    {
        try {
            $this->model->builder()
                ->where('id', $id)
                ->delete();

            return $this->model->lastAffectedRows() === 1;
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
     * Uses {@see CookieName::fromTrusted()} for the name VO so a row that
     * predates the current invariant (e.g., a legacy migrated row whose
     * value is now too short) can still be read instead of poisoning
     * every list query with a `ValidationException` (closes 06/F7).
     * `fromTrusted()` is the rehydration-only counterpart of
     * `fromString()` — see the VO's docblock.
     *
     * @param array<int|string, bool|float|int|string|null> $data The database row
     * @return Cookie The domain entity
     */
    private function toDomainEntity(array $data): Cookie
    {
        return Cookie::reconstitute(
            id: (int) $data['id'],
            name: CookieName::fromTrusted((string) $data['name']),
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
    private function performSave(Cookie $cookie, ?Actor $actor = null): int
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
            $this->updateWithOptimisticLock($cookie, $data, $actor);
            $cookie->bumpVersion(AggregateHydrator::key());

            return $id;
        }

        // First insert: row and entity start at version 1. Subsequent updates
        // bump in lock-step so DB and in-memory version always agree.
        $cookie->bumpVersion(AggregateHydrator::key());
        $data['version'] = $cookie->getVersion();
        if ($actor !== null) {
            // Audit trail (B10): stamp the creator on first insert. Without
            // this every cloned domain inherits a Cookie that silently
            // forgets who created a row.
            $data['created_by'] = $actor->id;
            $data['updated_by'] = $actor->id;
        }
        // Tenant scoping (B11): stamp the active tenant on insert. The
        // composite UNIQUE(tenant_id, name, deleted_at) relies on this
        // being a real integer; falling back to the default (1) keeps
        // the index enforcing uniqueness on single-tenant deploys.
        if ($this->tenantContext !== null) {
            $data['tenant_id'] = $this->tenantContext->currentTenantId();
        }

        $newId = (int) $this->model->insert($data);
        // Hydrate the entity so subsequent saves take the UPDATE path and
        // optimistic locking applies.
        $cookie->assignId($newId, AggregateHydrator::key());

        return $newId;
    }

    /**
     * @param array<string, scalar|null> $data
     */
    private function updateWithOptimisticLock(Cookie $cookie, array $data, ?Actor $actor = null): void
    {
        $expectedVersion = $cookie->getVersion();
        $now = date('Y-m-d H:i:s');
        $data['version'] = $expectedVersion + 1;
        $data['updated_at'] = $now;
        if ($actor !== null) {
            $data['updated_by'] = $actor->id;
        }

        $builder = $this->model->builder();
        $builder->where('id', $cookie->getId())
            ->where('version', $expectedVersion)
            ->update($data);

        // Wrapper hides the model's leaky `$db` access (06/F11). The
        // wrapper resolves the connection through the model itself so
        // the repository doesn't reach into framework internals.
        $affected = $this->model->lastAffectedRows();

        if ($affected === 1) {
            return;
        }

        $this->raiseConcurrentModification($cookie, $expectedVersion);
    }

    /**
     * raiseConcurrentModification.
     *
     * @throws DomainException
     */
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
     * @throws \RuntimeException
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
     * @throws \RuntimeException
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
            // Pre-escape `%`, `_`, and the escape char `!` in the term
            // so user input is matched as a literal substring (06/F4 —
            // same contract as the read repository's findPaginated).
            // CI4's `like()` does NOT auto-scrub bound values; it only
            // emits `ESCAPE '!'` when `$escape = true`.
            $escaped = strtr($searchTerm, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $builder->like('name', $escaped, 'both', true);
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

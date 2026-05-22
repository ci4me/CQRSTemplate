<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Repositories;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieQueryRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Infrastructure\Attributes\AutoBind;
use App\Infrastructure\Attributes\InfrastructureAdapter;
use App\Infrastructure\Tenancy\TenantContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * SQL-backed read of the canonical `cookies` table.
 *
 * Sister of {@see CookieRepository}, but for the read path. Query handlers
 * depend on this port; the implementation now reads straight from the
 * canonical `cookies` table rather than a separate `cookie_read_model`
 * projection — see plan "jazzy-drifting-mist" Phase 2.
 *
 * Why keep a separate read repository:
 *  - returns DTOs ({@see CookieDTO}), never domain entities — query handlers
 *    can't accidentally mutate the aggregate, and the read path skips the
 *    cost of reconstituting value objects.
 *  - groups all read-side concerns (DTO mapping, formatted price, tenant
 *    filtering, pagination) in one class that the write side does not
 *    depend on.
 *
 * The pre-Phase-2 implementation read from a denormalised projection table
 * with precomputed `price_formatted` / `name_search` columns; the
 * reference projection is preserved at
 * `app/Domain/Cookie/Projections/CookieReadModelProjection.php.example`.
 */
#[InfrastructureAdapter]
#[AutoBind]
final class CookieQueryRepository implements CookieQueryRepositoryInterface
{
    private const string TABLE = 'cookies';

    /**
     * Connection injection: null means "resolve through the framework's
     * Database helper". Closes 06/F15 — the previous
     * BaseConnection<TConnection, TResult> template was over-specified
     * and made test injection awkward.
     *
     * Tenant context: when provided, every read is scoped to the active
     * tenant via WHERE tenant_id = ?. Null preserves the legacy
     * single-tenant-deploy behaviour where every row stays visible.
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        private readonly ?TenantContext $tenantContext = null
    ) {
    }

    /**
     * findById.
     */
    public function findById(int $cookieId): ?CookieDTO
    {
        $builder = $this->connection()
            ->table(self::TABLE)
            ->where('id', $cookieId)
            ->where('deleted_at', null);

        $this->applyTenantFilter($builder);

        $result = $builder->get();
        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        return $row === null ? null : $this->toDto($row);
    }

    /**
     * @return list<CookieDTO>
     */
    public function findAll(bool $includeInactive = false): array
    {
        $builder = $this->connection()
            ->table(self::TABLE)
            ->where('deleted_at', null)
            ->orderBy('id', 'ASC');

        if (!$includeInactive) {
            $builder->where('is_active', 1);
        }

        $this->applyTenantFilter($builder);

        $result = $builder->get();
        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();
        return array_map(fn(array $row): CookieDTO => $this->toDto($row), $rows);
    }

    /**
     * @return array{data: list<CookieDTO>, total: int, page: int, perPage: int, lastPage: int}
     */
    public function findPaginated(
        int $page = 1,
        int $perPage = 20,
        ?string $searchTerm = null,
        bool $includeInactive = false
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $builder = $this->connection()
            ->table(self::TABLE)
            ->where('deleted_at', null);

        if (!$includeInactive) {
            $builder->where('is_active', 1);
        }

        $this->applyTenantFilter($builder);

        if ($searchTerm !== null && $searchTerm !== '') {
            // The `cookies.name` column is pinned to utf8mb4_unicode_ci
            // (see CreateCookiesTable migration), so LIKE is naturally
            // case-insensitive without needing a separate `name_search`
            // column.
            //
            // Closes 06/F4 — user-input wildcard leak. CI4's `like()`
            // does NOT pre-escape the bound value; it only emits an
            // `ESCAPE '!'` clause when `$escape = true`. We therefore
            // pre-escape `%`, `_`, and the escape char `!` itself in
            // the term so user input is matched as a literal substring.
            $escaped = self::escapeLikeWildcards($searchTerm);
            $builder->like('name', $escaped, 'both', true);
        }

        $total = $builder->countAllResults(false);

        $offset = ($page - 1) * $perPage;
        $result = $builder
            ->orderBy('id', 'ASC')
            ->limit($perPage, $offset)
            ->get();

        if ($result === false) {
            // Symmetric with the write side's executeFindPaginated: a
            // false get() means the SELECT itself failed. Silently
            // returning empty data with non-zero total hides the
            // problem (closes 06/F10).
            throw new \RuntimeException('Cookie findPaginated query failed');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();

        $data = array_map(fn(array $row): CookieDTO => $this->toDto($row), $rows);
        // countAllResults is typed `int|string` upstream (CI4 quirk on
        // some drivers); cast once for the math. $perPage is clamped to
        // 1..100 above so no divide-by-zero is reachable.
        $totalInt = (int) $total;
        $lastPage = (int) ceil($totalInt / $perPage);

        return [
            'data' => $data,
            'total' => $totalInt,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, $lastPage),
        ];
    }

    /**
     * Map a `cookies` row to the read-side DTO.
     *
     * The pre-Phase-2 projection table carried `price_decimal` /
     * `price_formatted` precomputed; the canonical `cookies` table only
     * stores the raw decimal `price`, so the formatted variant is
     * derived in PHP via {@see CookiePrice::format()}. Malformed prices
     * fall back to a safe default rather than throwing — read paths
     * should not blow up on bad source data.
     *
     * @param array<string, mixed> $row
     */
    private function toDto(array $row): CookieDTO
    {
        $price = (string) ($row['price'] ?? '0.00');
        $formattedPrice = $this->formatPrice($price);

        return new CookieDTO(
            id: (int) $row['id'],
            name: (string) $row['name'],
            description: isset($row['description']) && is_string($row['description']) ? $row['description'] : null,
            price: $price,
            formattedPrice: $formattedPrice,
            stock: (int) ($row['stock'] ?? 0),
            isActive: (bool) ($row['is_active'] ?? 0),
            createdAt: isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
        );
    }

    /**
     * Best-effort formatting of a stored decimal price.
     */
    private function formatPrice(string $decimalPrice): string
    {
        try {
            return CookiePrice::fromString($decimalPrice)->format();
        } catch (\Throwable) {
            // Defensive: read paths should not crash on malformed source
            // rows. Returning the raw decimal keeps the UI usable.
            return $decimalPrice;
        }
    }

    /**
     * connection.
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }

    /**
     * Escape SQL `LIKE` wildcards in a user-supplied search term.
     *
     * CI4's `like()` does not auto-escape `%` / `_` in the bound value;
     * it only emits an `ESCAPE '!'` clause when `$escape = true`. We
     * therefore pre-escape with the same character (`!`) so the bound
     * value matches as a literal substring. The escape character
     * itself is doubled first so an end-user-typed `!` does not act as
     * the escape for the next character (closes 06/F4).
     *
     * NOTE: the escape character (`!`) is CI4's connection default
     * (see `BaseConnection::$likeEscapeChar`). If a future migration
     * to a driver that overrides that default lands, this helper must
     * read from the connection rather than hard-coding `!`.
     */
    private static function escapeLikeWildcards(string $term): string
    {
        return strtr($term, [
            '!' => '!!',
            '%' => '!%',
            '_' => '!_',
        ]);
    }

    /**
     * Scope a query builder to the active tenant.
     *
     * When `tenantContext` is null (legacy single-tenant tests), this is a
     * no-op and every row stays visible. When wired through Services, the
     * filter restricts reads to the current tenant's slice — the write
     * side stamps `tenant_id` on every insert (see CookieRepository) so
     * the columns line up.
     */
    private function applyTenantFilter(\CodeIgniter\Database\BaseBuilder $builder): void
    {
        if ($this->tenantContext === null) {
            return;
        }
        $builder->where('tenant_id', $this->tenantContext->currentTenantId());
    }
}

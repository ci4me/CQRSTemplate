<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories;

use App\Domain\Cookie\DTOs\CookieDTO;
use App\Domain\Cookie\Ports\CookieReadModelRepositoryInterface;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * SQL-backed read of the `cookie_read_model` table.
 *
 * Sister of {@see CookieRepository}, but for the read path. Query handlers
 * depend on the port; the projection ({@see \App\Domain\Cookie\Projections\CookieReadModelProjection})
 * keeps the table in sync with the write side.
 *
 * Why a separate read repository:
 *  - the `cookie_read_model` row already carries the formatted price and
 *    `available` flag, so we can hand the UI a ready-made DTO without
 *    re-parsing the source row's `price` decimal or computing isAvailable
 *    in PHP.
 *  - soft-deleted rows are filtered here in one place; the source repo
 *    has its own filter for the write path.
 */
final class CookieReadModelRepository implements CookieReadModelRepositoryInterface
{
    private const string TABLE = 'cookie_read_model';

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    public function findById(int $cookieId): ?CookieDTO
    {
        $result = $this->connection()
            ->table(self::TABLE)
            ->where('cookie_id', $cookieId)
            ->where('deleted_at', null)
            ->get();

        if ($result === false) {
            return null;
        }

        $row = $result->getRowArray();
        return $row === null ? null : $this->toDto($row);
    }

    public function findAll(bool $includeInactive = false): array
    {
        $builder = $this->connection()
            ->table(self::TABLE)
            ->where('deleted_at', null)
            ->orderBy('cookie_id', 'ASC');

        if (!$includeInactive) {
            $builder->where('is_active', 1);
        }

        $result = $builder->get();
        if ($result === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();
        return array_map(fn(array $row): CookieDTO => $this->toDto($row), $rows);
    }

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

        if ($searchTerm !== null && $searchTerm !== '') {
            // The projection maintains `name_search` as a lower-cased copy
            // so this stays an indexed match regardless of the input's case.
            $builder->like('name_search', strtolower($searchTerm));
        }

        $total = $builder->countAllResults(false);

        $offset = ($page - 1) * $perPage;
        $result = $builder
            ->orderBy('cookie_id', 'ASC')
            ->limit($perPage, $offset)
            ->get();

        $rows = $result === false ? [] : $result->getResultArray();
        /** @var list<array<string, mixed>> $rows */

        $data = array_map(fn(array $row): CookieDTO => $this->toDto($row), $rows);
        $lastPage = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'lastPage' => max(1, $lastPage),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDto(array $row): CookieDTO
    {
        return new CookieDTO(
            id: (int) $row['cookie_id'],
            name: (string) $row['name'],
            description: isset($row['description']) && is_string($row['description']) ? $row['description'] : null,
            price: (string) ($row['price_decimal'] ?? '0.00'),
            formattedPrice: (string) ($row['price_formatted'] ?? ''),
            stock: (int) ($row['stock'] ?? 0),
            isActive: (bool) ($row['is_active'] ?? 0),
            createdAt: isset($row['created_at']) && is_string($row['created_at']) ? $row['created_at'] : null,
            updatedAt: isset($row['updated_at']) && is_string($row['updated_at']) ? $row['updated_at'] : null,
        );
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

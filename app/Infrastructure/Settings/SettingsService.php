<?php

declare(strict_types=1);

namespace App\Infrastructure\Settings;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Runtime settings store backed by the `settings` table (D10).
 *
 * Reads are cached for the lifetime of the request (one row -> one cache
 * entry, keyed by `key:tenant_id`). The cache is cleared on every write so
 * a setting change is visible to the rest of the request without a second
 * DB read.
 *
 * Tenant scoping:
 *  - When `$tenantId` is supplied, the lookup is exact (no fallback).
 *  - When omitted, the global row (tenant_id IS NULL) is read.
 *  - When a tenant-scoped setting doesn't exist, callers should
 *    explicitly fall back to the global one — keeping the call sites
 *    obvious.
 *
 * Type handling: values are stored as JSON so the read side knows whether
 * "42" is a string or an int. The `type` column is informational (for the
 * future admin UI); it does not coerce.
 */
final class SettingsService
{
    /** @var array<string, mixed> */
    private array $cache = [];

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     */
    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Look up a setting; return $default when no row matches.
     *
     * @param string   $key
     * @param mixed    $default
     * @param int|null $tenantId
     * @return mixed
     */
    public function get(string $key, mixed $default = null, ?int $tenantId = null): mixed
    {
        $cacheKey = $this->cacheKey($key, $tenantId);
        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $row = $this->fetchRow($key, $tenantId);

        if ($row === null) {
            $this->cache[$cacheKey] = $default;
            return $default;
        }

        $value = $this->decodeValue((string) $row['value_json']);
        $this->cache[$cacheKey] = $value;
        return $value;
    }

    /**
     * Upsert a setting. Stores `value` as JSON and refreshes the cache.
     *
     * @param string      $key
     * @param mixed       $value
     * @param int|null    $tenantId
     * @param string      $type
     * @param string|null $description
     * @param bool        $isSecret
     * @return void
     */
    public function set(
        string $key,
        mixed $value,
        ?int $tenantId = null,
        string $type = 'string',
        ?string $description = null,
        bool $isSecret = false
    ): void {
        $now = date('Y-m-d H:i:s');
        $payload = [
            'value_json' => $this->encodeValue($value),
            'type' => $type,
            'description' => $description,
            'is_secret' => $isSecret ? 1 : 0,
            'updated_at' => $now,
        ];

        $db = $this->connection();
        $existingId = $this->existingRowId($key, $tenantId);

        if ($existingId === null) {
            $payload['key_name'] = $key;
            $payload['tenant_id'] = $tenantId;
            $payload['created_at'] = $now;
            $db->table('settings')->insert($payload);
        } else {
            $db->table('settings')->where('id', $existingId)->update($payload);
        }

        $this->cache[$this->cacheKey($key, $tenantId)] = $value;
    }

    /**
     * Remove a setting. Idempotent — no-op if the row does not exist.
     *
     * @param string   $key
     * @param int|null $tenantId
     * @return void
     */
    public function forget(string $key, ?int $tenantId = null): void
    {
        $this->connection()
            ->table('settings')
            ->where('key_name', $key)
            ->where('tenant_id', $tenantId)
            ->delete();

        unset($this->cache[$this->cacheKey($key, $tenantId)]);
    }

    /**
     * has.
     *
     * @param string   $key
     * @param int|null $tenantId
     * @return bool
     */
    public function has(string $key, ?int $tenantId = null): bool
    {
        return $this->fetchRow($key, $tenantId) !== null;
    }

    /**
     * Drop the in-memory cache. Mostly useful from tests; production code
     * shouldn't need to touch it because set/forget already invalidate.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * @param string   $key
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $key, ?int $tenantId): ?array
    {
        $builder = $this->connection()
            ->table('settings')
            ->where('key_name', $key);

        if ($tenantId === null) {
            $builder->where('tenant_id', null);
        } else {
            $builder->where('tenant_id', $tenantId);
        }

        $result = $builder->get();
        if ($result === false) {
            return null;
        }

        /** @var array<string, mixed>|null $row */
        $row = $result->getRowArray();
        return $row;
    }

    /**
     * existingRowId.
     *
     * @param string   $key
     * @param int|null $tenantId
     * @return int|null
     */
    private function existingRowId(string $key, ?int $tenantId): ?int
    {
        $row = $this->fetchRow($key, $tenantId);
        if ($row === null) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * decodeValue.
     *
     * @param string $json
     * @return mixed
     */
    private function decodeValue(string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $json;
        }
    }

    /**
     * encodeValue.
     *
     * @param mixed $value
     * @return string
     */
    private function encodeValue(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * cacheKey.
     *
     * @param string   $key
     * @param int|null $tenantId
     * @return string
     */
    private function cacheKey(string $key, ?int $tenantId): string
    {
        return $key . ':' . ($tenantId === null ? 'global' : (string) $tenantId);
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

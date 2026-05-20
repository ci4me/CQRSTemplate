<?php

declare(strict_types=1);

namespace App\Infrastructure\Tenancy;

use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\Session\Session;

/**
 * Resolves the active tenant for the current execution context.
 *
 * Read order:
 *  1. Explicit `set()` override (CLI runs that bind a tenant by hand).
 *  2. `X-Tenant-Id` header on an attached {@see RequestInterface}.
 *  3. `tenant_id` value in the session (web tier).
 *  4. `DEFAULT_TENANT_ID` env var.
 *  5. Single-tenant fallback: `1`.
 *
 * The fallback to `1` is intentional. Cloned domains inherit a column
 * called `tenant_id` that's already part of the unique index; making the
 * default a real integer (not NULL) is what stops MySQL's NULL-is-not-
 * equal-to-NULL behaviour from turning the composite UNIQUE into a
 * no-op. Multi-tenant deployments override via session / header / env.
 *
 * The service is intentionally NOT a value object — its value changes
 * per request. Repositories pull from {@see self::currentTenantId()}
 * inside save() and query methods.
 */
final class TenantContext
{
    public const string HEADER = 'X-Tenant-Id';
    public const int DEFAULT_TENANT_ID = 1;

    private ?int $override = null;

    public function __construct(
        private readonly ?RequestInterface $request = null
    ) {
    }

    /**
     * Force a specific tenant id for the rest of this execution context.
     *
     * Used by CLI tasks that operate against a specific tenant
     * (`spark projections:rebuild --tenant=2`) and by tests that need a
     * deterministic value.
     */
    public function set(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new \InvalidArgumentException(sprintf('TenantContext: id must be >= 1, got %d', $tenantId));
        }
        $this->override = $tenantId;
    }

    /**
     * Clear an earlier {@see self::set()} override, restoring the normal
     * read order.
     */
    public function clear(): void
    {
        $this->override = null;
    }

    public function currentTenantId(): int
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $fromHeader = $this->tenantFromHeader();
        if ($fromHeader !== null) {
            return $fromHeader;
        }

        $fromSession = $this->tenantFromSession();
        if ($fromSession !== null) {
            return $fromSession;
        }

        $fromEnv = getenv('DEFAULT_TENANT_ID');
        if (is_string($fromEnv) && ctype_digit($fromEnv)) {
            return max(1, (int) $fromEnv);
        }

        return self::DEFAULT_TENANT_ID;
    }

    private function tenantFromHeader(): ?int
    {
        if ($this->request === null) {
            return null;
        }
        $raw = trim($this->request->getHeaderLine(self::HEADER));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;
        return $value >= 1 ? $value : null;
    }

    private function tenantFromSession(): ?int
    {
        if (!function_exists('session')) {
            return null;
        }
        $session = session();
        if (!$session instanceof Session) {
            return null;
        }
        $value = $session->get('tenant_id');
        if (is_int($value) && $value >= 1) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value >= 1) {
            return (int) $value;
        }
        return null;
    }
}

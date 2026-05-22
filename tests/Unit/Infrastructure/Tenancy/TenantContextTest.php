<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Tenancy;

use App\Infrastructure\Tenancy\TenantContext;
use CodeIgniter\HTTP\RequestInterface;
use Tests\Support\UnitTestCase;

/**
 * Pins the TenantContext read order: override > header > session > env >
 * default(1). The default is what keeps cookies' composite UNIQUE index
 * enforcing uniqueness on single-tenant deploys, so it gets explicit
 * coverage.
 */
final class TenantContextTest extends UnitTestCase
{
    public function test_default_tenant_id_is_one_when_nothing_else_is_set(): void
    {
        $ctx = new TenantContext();
        $this->assertSame(1, $ctx->currentTenantId());
    }

    public function test_set_override_wins_over_every_other_source(): void
    {
        $ctx = new TenantContext();
        $ctx->set(42);
        $this->assertSame(42, $ctx->currentTenantId());
    }

    public function test_set_rejects_zero_and_negative(): void
    {
        $ctx = new TenantContext();
        $this->expectException(\InvalidArgumentException::class);
        $ctx->set(0);
    }

    public function test_clear_restores_normal_read_order(): void
    {
        $ctx = new TenantContext();
        $ctx->set(99);
        $this->assertSame(99, $ctx->currentTenantId());

        $ctx->clear();
        $this->assertSame(1, $ctx->currentTenantId());
    }

    public function test_request_header_is_honoured_when_no_override(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')
            ->with(TenantContext::HEADER)
            ->willReturn('7');

        $ctx = new TenantContext($request);
        $this->assertSame(7, $ctx->currentTenantId());
    }

    public function test_request_header_with_non_numeric_value_is_ignored(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getHeaderLine')
            ->with(TenantContext::HEADER)
            ->willReturn('abc');

        $ctx = new TenantContext($request);
        $this->assertSame(1, $ctx->currentTenantId(), 'fallback should be 1');
    }

    public function test_override_beats_header(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getHeaderLine')->willReturn('7');

        $ctx = new TenantContext($request);
        $ctx->set(123);
        $this->assertSame(123, $ctx->currentTenantId());
    }

    public function test_header_value_of_zero_is_treated_as_missing(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getHeaderLine')->willReturn('0');

        $ctx = new TenantContext($request);
        $this->assertSame(1, $ctx->currentTenantId(), 'header=0 falls through to default(1)');
    }

    public function test_default_tenant_id_env_var_is_honoured(): void
    {
        putenv('DEFAULT_TENANT_ID=7');
        try {
            $this->assertSame(7, (new TenantContext())->currentTenantId());
        } finally {
            putenv('DEFAULT_TENANT_ID');
        }
    }

    public function test_default_tenant_id_env_var_clamps_to_one_minimum(): void
    {
        putenv('DEFAULT_TENANT_ID=0');
        try {
            $this->assertSame(1, (new TenantContext())->currentTenantId());
        } finally {
            putenv('DEFAULT_TENANT_ID');
        }
    }

    public function test_default_tenant_id_env_var_with_non_digit_value_is_ignored(): void
    {
        putenv('DEFAULT_TENANT_ID=invalid-string');
        try {
            $this->assertSame(1, (new TenantContext())->currentTenantId());
        } finally {
            putenv('DEFAULT_TENANT_ID');
        }
    }
}

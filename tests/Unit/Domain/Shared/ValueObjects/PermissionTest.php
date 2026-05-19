<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\ValueObjects;

use App\Domain\Shared\ValueObjects\Permission;
use Tests\Support\UnitTestCase;

final class PermissionTest extends UnitTestCase
{
    public function test_valid_permission_is_parsed(): void
    {
        $p = Permission::fromString('cookies.create');
        $this->assertSame('cookies', $p->module);
        $this->assertSame('create', $p->action);
        $this->assertSame('cookies.create', $p->name);
    }

    public function test_input_is_normalised_to_lowercase(): void
    {
        $p = Permission::fromString('  Cookies.Update ');
        $this->assertSame('cookies.update', $p->name);
    }

    public function test_module_with_underscore_and_digits_is_allowed(): void
    {
        $p = Permission::fromString('accounts_payable.post_invoice2');
        $this->assertSame('accounts_payable', $p->module);
        $this->assertSame('post_invoice2', $p->action);
    }

    public function test_missing_action_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::fromString('cookies');
    }

    public function test_missing_module_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::fromString('.create');
    }

    public function test_leading_digit_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::fromString('1cookies.create');
    }

    public function test_dashes_and_spaces_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Permission::fromString('cookies.create-new');
    }

    public function test_equals_compares_normalised_name(): void
    {
        $this->assertTrue(
            Permission::fromString('cookies.create')->equals(Permission::fromString('Cookies.CREATE'))
        );
    }

    public function test_string_cast_returns_full_name(): void
    {
        $p = Permission::fromString('invoices.post');
        $this->assertSame('invoices.post', (string) $p);
    }
}

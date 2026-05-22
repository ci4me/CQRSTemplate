<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\User\ValueObjects\UserRole;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for UserRole enum.
 *
 * Exercises label, predicate helpers, getDescription, and values() listing
 * so every case branch is covered.
 */
final class UserRoleTest extends UnitTestCase
{
    public function test_admin_role_predicates_and_label(): void
    {
        $role = UserRole::Admin;

        $this->assertSame('admin', $role->value);
        $this->assertSame('Administrator', $role->label());
        $this->assertTrue($role->isAdmin());
        $this->assertFalse($role->isCustomer());
        $this->assertFalse($role->isGuest());
    }

    public function test_customer_role_predicates_and_label(): void
    {
        $role = UserRole::Customer;

        $this->assertSame('customer', $role->value);
        $this->assertSame('Customer', $role->label());
        $this->assertFalse($role->isAdmin());
        $this->assertTrue($role->isCustomer());
        $this->assertFalse($role->isGuest());
    }

    public function test_guest_role_predicates_and_label(): void
    {
        $role = UserRole::Guest;

        $this->assertSame('guest', $role->value);
        $this->assertSame('Guest', $role->label());
        $this->assertFalse($role->isAdmin());
        $this->assertFalse($role->isCustomer());
        $this->assertTrue($role->isGuest());
    }

    public function test_get_description_for_each_role(): void
    {
        $this->assertStringContainsString('Full system access', UserRole::Admin->getDescription());
        $this->assertStringContainsString('Standard user', UserRole::Customer->getDescription());
        $this->assertStringContainsString('Limited access', UserRole::Guest->getDescription());
    }

    public function test_values_returns_all_role_strings(): void
    {
        $this->assertSame(['admin', 'customer', 'guest'], UserRole::values());
    }
}

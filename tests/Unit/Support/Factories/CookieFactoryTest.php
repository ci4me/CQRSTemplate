<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Factories;

use App\Domain\Cookie\Entities\Cookie;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

/**
 * Regression tests for {@see CookieFactory} — the Test Data Builder used
 * across the Cookie unit + integration suites.
 *
 * Origin: audit slice 12/F12 + missing-8 flagged that
 * `createPersistedCookie` accepted a `version` key in its overrides
 * array but the array_merge / hard-coded `version: 1` on the
 * reconstitute() call meant the override was silently dropped — a
 * footgun for any cloned domain that adds version-aware tests (Order
 * revision-tracking, Invoice audit numbers, etc.).
 *
 * These tests pin the override-respecting contract on every method so a
 * future "tidy-up" cannot silently regress it.
 */
final class CookieFactoryTest extends UnitTestCase
{
    public function test_create_cookie_returns_cookie_with_defaults(): void
    {
        $cookie = CookieFactory::createCookie();

        $this->assertInstanceOf(Cookie::class, $cookie);
        $this->assertSame('Chocolate Chip Cookie', $cookie->getName()->getValue());
        $this->assertSame(100, $cookie->getStock());
        $this->assertTrue($cookie->getIsActive());
        $this->assertNull($cookie->getId(), 'createCookie returns an unpersisted entity');
    }

    public function test_create_cookie_respects_name_override(): void
    {
        $cookie = CookieFactory::createCookie(['name' => 'Snickerdoodle']);

        $this->assertSame('Snickerdoodle', $cookie->getName()->getValue());
    }

    public function test_create_cookie_respects_stock_override(): void
    {
        $cookie = CookieFactory::createCookie(['stock' => 42]);

        $this->assertSame(42, $cookie->getStock());
    }

    public function test_create_cookie_respects_is_active_override(): void
    {
        $cookie = CookieFactory::createCookie(['isActive' => false]);

        $this->assertFalse($cookie->getIsActive());
    }

    public function test_create_persisted_cookie_returns_persisted_entity(): void
    {
        $cookie = CookieFactory::createPersistedCookie();

        $this->assertSame(1, $cookie->getId());
        $this->assertSame(1, $cookie->getVersion(), 'default version is 1 (the post-insert value)');
    }

    public function test_create_persisted_cookie_respects_version_override(): void
    {
        // Audit slice 12/F12: `version` was a documented override key
        // but the factory silently dropped it because the `version: 1`
        // argument to reconstitute() was hard-coded. This test pins the
        // fix: version overrides round-trip end-to-end.
        $cookie = CookieFactory::createPersistedCookie(['version' => 99]);

        $this->assertSame(99, $cookie->getVersion());
    }

    public function test_create_persisted_cookie_respects_id_override(): void
    {
        $cookie = CookieFactory::createPersistedCookie(['id' => 42]);

        $this->assertSame(42, $cookie->getId());
    }

    public function test_create_persisted_cookie_respects_deleted_at_override(): void
    {
        $cookie = CookieFactory::createPersistedCookie([
            'deletedAt' => '2026-01-01 10:00:00',
        ]);

        $this->assertTrue($cookie->isDeleted());
        $this->assertSame('2026-01-01 10:00:00', $cookie->getDeletedAt());
    }

    public function test_create_multiple_returns_distinct_cookies_with_unique_names(): void
    {
        $cookies = CookieFactory::createMultiple(3);

        $this->assertCount(3, $cookies);
        $names = array_map(static fn(Cookie $c): string => $c->getName()->getValue(), $cookies);
        $this->assertCount(3, array_unique($names), 'each cookie has a unique name');
    }

    public function test_create_database_row_returns_array_with_defaults(): void
    {
        $row = CookieFactory::createDatabaseRow();

        $this->assertSame(1, $row['id']);
        $this->assertSame('Chocolate Chip Cookie', $row['name']);
        $this->assertSame(1, $row['is_active']);
        $this->assertNull($row['deleted_at']);
    }

    public function test_create_database_row_respects_overrides(): void
    {
        $row = CookieFactory::createDatabaseRow(['id' => 99, 'name' => 'Custom']);

        $this->assertSame(99, $row['id']);
        $this->assertSame('Custom', $row['name']);
    }

    public function test_create_form_data_returns_string_typed_payload(): void
    {
        $data = CookieFactory::createFormData();

        // Form input is always string-typed — pin that contract so
        // controllers that depend on string casting do not break.
        $this->assertIsString($data['stock']);
        $this->assertIsString($data['price']);
    }

    public function test_create_invalid_form_data_for_name_empty(): void
    {
        $data = CookieFactory::createInvalidFormData('name_empty');

        $this->assertSame('', $data['name']);
    }

    public function test_create_invalid_form_data_for_unknown_field_returns_valid_payload(): void
    {
        $data = CookieFactory::createInvalidFormData('unknown');

        // Default arm returns the valid form payload — the match
        // expression's `default` is part of the contract.
        $this->assertSame('Chocolate Chip Cookie', $data['name']);
    }
}

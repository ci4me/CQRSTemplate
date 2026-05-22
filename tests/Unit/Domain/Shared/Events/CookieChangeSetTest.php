<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Events;

use App\Domain\Shared\Events\CookieChangeSet;
use Tests\Support\UnitTestCase;

/**
 * Whitelist enforcement contract for {@see CookieChangeSet}.
 *
 * @package Tests\Unit\Domain\Shared\Events
 */
final class CookieChangeSetTest extends UnitTestCase
{
    public function test_allowed_keys_are_accepted_and_returned_verbatim(): void
    {
        $changes = [
            'id' => 7,
            'name' => 'Updated Cookie',
            'description' => 'desc',
            'price_minor' => 299,
            'price_currency' => 'USD',
            'stock' => 100,
            'is_active' => true,
            'version' => 2,
            'deleted_at' => null,
        ];

        $set = CookieChangeSet::fromArray($changes);

        $this->assertSame($changes, $set->toArray());
        $this->assertFalse($set->isEmpty());
    }

    public function test_unknown_key_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // The exception MUST name the offending key so reviewers can
        // diagnose without launching a debugger.
        $this->expectExceptionMessage('email');

        CookieChangeSet::fromArray([
            'name' => 'Customer Cookie',
            'email' => 'leak@example.com', // not whitelisted
        ]);
    }

    public function test_empty_factory_returns_an_empty_change_set(): void
    {
        $set = CookieChangeSet::empty();

        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->toArray());
    }

    public function test_partial_change_set_is_valid(): void
    {
        // Partial diff is the common case for UpdateCookie: only the
        // touched columns appear in the snapshot.
        $set = CookieChangeSet::fromArray([
            'name' => 'Renamed',
            'price_minor' => 599,
        ]);

        $this->assertSame(['name' => 'Renamed', 'price_minor' => 599], $set->toArray());
    }

    public function test_allowed_keys_constant_is_the_authoritative_whitelist(): void
    {
        // Regression guard: adding a new field to the whitelist is a
        // deliberate change reviewers must see in diffs.
        $expected = [
            'id',
            'name',
            'description',
            'price_minor',
            'price_currency',
            'stock',
            'is_active',
            'version',
            'deleted_at',
        ];

        $this->assertSame($expected, CookieChangeSet::ALLOWED_KEYS);
    }

    public function test_direct_constructor_also_enforces_whitelist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CookieChangeSet(['unknown_column' => 'x']);
    }
}

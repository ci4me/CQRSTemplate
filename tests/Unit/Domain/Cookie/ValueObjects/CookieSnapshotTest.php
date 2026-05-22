<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ValueObjects\CookieSnapshot;
use App\Domain\Shared\Events\CookieChangeSet;
use Tests\Support\UnitTestCase;

/**
 * CookieSnapshot is the typed VO that replaces the original heterogeneous
 * snapshot array on the entity (round-3 audit 01/F11). These tests pin
 * the contract: it wraps a {@see CookieChangeSet}, exposes the same
 * empty-detection / array round-trip surface, and refuses unknown keys
 * via the underlying change-set guard.
 */
final class CookieSnapshotTest extends UnitTestCase
{
    public function test_constructs_from_change_set(): void
    {
        $changeSet = CookieChangeSet::fromArray(['id' => 1, 'name' => 'Choc Chip']);
        $snapshot = new CookieSnapshot($changeSet);

        $this->assertSame($changeSet, $snapshot->toChangeSet());
    }

    public function test_from_array_builds_via_change_set_whitelist(): void
    {
        $snapshot = CookieSnapshot::fromArray(['id' => 5, 'stock' => 12]);

        $this->assertSame(['id' => 5, 'stock' => 12], $snapshot->toArray());
    }

    public function test_from_array_rejects_unknown_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rejects unknown key "totally_made_up"');

        CookieSnapshot::fromArray(['totally_made_up' => 'leak']);
    }

    public function test_empty_snapshot_reports_empty(): void
    {
        $snapshot = CookieSnapshot::fromArray([]);

        $this->assertTrue($snapshot->isEmpty());
        $this->assertSame([], $snapshot->toArray());
    }

    public function test_populated_snapshot_is_not_empty(): void
    {
        $snapshot = CookieSnapshot::fromArray(['id' => 1]);

        $this->assertFalse($snapshot->isEmpty());
    }
}

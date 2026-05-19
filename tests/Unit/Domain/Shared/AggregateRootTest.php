<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared;

use App\Domain\Shared\AggregateRoot;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for the AggregateRoot trait. Uses an inline anonymous class
 * so the trait is exercised in isolation from any specific domain entity.
 */
final class AggregateRootTest extends UnitTestCase
{
    public function test_starts_with_empty_event_buffer(): void
    {
        $agg = $this->fixture();

        $this->assertFalse($agg->hasPendingEvents());
        $this->assertSame([], $agg->peekEvents());
        $this->assertSame([], $agg->pullEvents());
    }

    public function test_raise_event_appends_to_buffer(): void
    {
        $agg = $this->fixture();

        $e1 = new \stdClass();
        $e2 = new \stdClass();
        $agg->raiseEventPublic($e1);
        $agg->raiseEventPublic($e2);

        $this->assertTrue($agg->hasPendingEvents());
        $this->assertSame([$e1, $e2], $agg->peekEvents());
    }

    public function test_pull_events_returns_and_clears(): void
    {
        $agg = $this->fixture();

        $e1 = new \stdClass();
        $agg->raiseEventPublic($e1);

        $pulled = $agg->pullEvents();

        $this->assertSame([$e1], $pulled);
        $this->assertSame([], $agg->pullEvents(), 'second pull yields empty list');
        $this->assertFalse($agg->hasPendingEvents());
    }

    public function test_peek_does_not_clear(): void
    {
        $agg = $this->fixture();
        $agg->raiseEventPublic(new \stdClass());

        $agg->peekEvents();
        $agg->peekEvents();

        $this->assertTrue($agg->hasPendingEvents());
        $this->assertCount(1, $agg->pullEvents());
    }

    private function fixture(): object
    {
        return new class {
            use AggregateRoot;

            public function raiseEventPublic(object $event): void
            {
                $this->raiseEvent($event);
            }
        };
    }
}

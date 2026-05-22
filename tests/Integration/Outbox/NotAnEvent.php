<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

/**
 * Test fixture: a plain class that does NOT implement DomainEventInterface.
 * Used to verify the relay refuses to rehydrate non-event classes.
 */
final class NotAnEvent
{
    public function __construct(public int $id = 0)
    {
    }
}

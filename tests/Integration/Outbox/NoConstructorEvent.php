<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Test fixture: an event with no constructor (exercises the
 * `getConstructor() === null` branch of EventOutboxRelay::rehydrate).
 */
final class NoConstructorEvent implements DomainEventInterface
{
}

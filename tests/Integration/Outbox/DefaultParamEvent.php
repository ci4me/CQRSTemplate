<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Test fixture: an event whose second parameter has a default value
 * (exercises the `isDefaultValueAvailable()` branch of
 * EventOutboxRelay::rehydrate).
 */
final readonly class DefaultParamEvent implements DomainEventInterface
{
    public function __construct(
        public int $id,
        public string $note = 'default-note',
    ) {
    }
}

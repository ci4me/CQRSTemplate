<?php

declare(strict_types=1);

namespace Tests\Integration\Outbox;

/**
 * Concrete fixture event used by {@see EventOutboxTest}. Declared in its
 * own file so the relay can rehydrate it from a class-name string
 * (anonymous classes lose their name when JSON-encoded).
 */
final readonly class OutboxTestEvent
{
    public function __construct(
        public int $id,
        public string $note
    ) {
    }
}

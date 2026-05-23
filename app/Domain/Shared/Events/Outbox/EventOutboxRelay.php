<?php

namespace App\Domain\Shared\Events\Outbox;

use App\Domain\Shared\Events\AbstractDomainEvent;

final class EventOutboxRelay
{
    public function __construct(
        private readonly EventOutboxRepository $outboxRepo
    ) {}

    public function run(): void
    {
        // TODO: Implement relay with FOR UPDATE SKIP LOCKED
    }
}
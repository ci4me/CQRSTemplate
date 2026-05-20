<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs;

/**
 * Contract every job handler must implement (D6).
 *
 * Handlers receive their JSON-decoded payload as an associative array and
 * must complete (or throw) within the worker's per-job timeout. A handler
 * MUST be idempotent — the worker may re-run it if it fails or if a
 * supervisor restarts mid-execution.
 *
 * Throwing causes the worker to bump attempts and either reschedule the
 * job (with exponential backoff) or mark it failed when max_attempts is
 * exhausted.
 */
interface JobHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return void
     */
    public function handle(array $payload): void;
}

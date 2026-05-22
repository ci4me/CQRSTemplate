<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Shared\ValueObjects\Actor;

/**
 * Command to update an existing Cookie.
 *
 * Carries the {@see Actor} that initiated the update so the repository
 * can stamp the audit `updated_by` column, and the client's
 * `expectedVersion` so the handler can short-circuit a concurrent-write
 * race BEFORE incurring the cost of loading + mutating the entity.
 *
 * Optimistic-locking contract (E08 — closes 03/F9):
 *  - `expectedVersion` is REQUIRED (no default null). Callers MUST load
 *    the entity, read `$entity->getVersion()`, and pass that here. The
 *    handler compares the loaded entity's version BEFORE issuing the
 *    UPDATE and throws a domain-level concurrent-modification exception
 *    when they don't match.
 *  - Pre-E08 the field was `?int = null`, meaning a caller that simply
 *    forgot to pass it silently opted out of concurrency control and
 *    accepted last-write-wins. That "opt-in safety" pattern is precisely
 *    the kind of footgun this template must NOT model. If a caller truly
 *    wants last-write-wins they must say so explicitly by reading the
 *    current version and passing it — yielding a deterministic update.
 *  - Controllers MUST NOT pass an arbitrary integer (e.g. 0) here. The
 *    handler trusts the caller to have read a real version from the
 *    repository; passing a synthetic value reopens the race window.
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 */
final readonly class UpdateCookieCommand
{
    /**
     * @param int         $id              Persisted cookie id.
     * @param string      $name            Proposed name (validated via CookieName VO).
     * @param string|null $description     Proposed description (nullable).
     * @param string      $price           Proposed price (validated via CookiePrice VO).
     * @param int         $stock           Proposed stock count.
     * @param bool        $isActive        Proposed active flag.
     * @param Actor       $updatedBy       Audit actor stamping `updated_by`.
     * @param int         $expectedVersion REQUIRED — the version the caller observed when
     *                                     reading the entity. Used for optimistic locking.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive,
        public Actor $updatedBy,
        public int $expectedVersion
    ) {
    }
}

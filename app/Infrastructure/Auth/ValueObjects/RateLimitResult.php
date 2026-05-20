<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\ValueObjects;

use App\Domain\Shared\ValueObjects\RateLimitResult as DomainRateLimitResult;

/**
 * Back-compat shim — the canonical {@see DomainRateLimitResult} now lives
 * in the domain layer so {@see \App\Domain\User\Ports\RateLimitInterface}
 * can type-hint it without violating the dependency-direction rule
 * (domain MUST NOT depend on infrastructure). All existing call sites
 * that import this class keep compiling because `RateLimitResult`
 * extends the domain class and adds no new behaviour.
 *
 * @deprecated Prefer {@see DomainRateLimitResult} for new code.
 */
final class RateLimitResult extends DomainRateLimitResult
{
}

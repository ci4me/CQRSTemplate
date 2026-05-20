<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Events\CookieCreated;

use App\Domain\Shared\Events\DomainEventInterface;

/**
 * Event fired when a new Cookie is created.
 *
 * Domain Events represent facts that have happened in the domain.
 * They are:
 * - Named in past tense (CookieCreated, not CreateCookie)
 * - Immutable (you can't change history)
 * - Contain only essential data about what happened
 *
 * Why Domain Events:
 * - Enable loose coupling between bounded contexts
 * - Provide audit trail of what happened
 * - Allow side effects without tight coupling
 * - Foundation for event sourcing if needed
 *
 * Use Cases:
 * - Log cookie creation for audit
 * - Send notification to inventory system
 * - Update analytics/metrics
 * - Trigger cache invalidation
 *
 * @package App\Domain\Cookie\Events\CookieCreated
 */
final readonly class CookieCreatedEvent implements DomainEventInterface
{
    /**
     * Create a new CookieCreatedEvent.
     *
     * @param int    $cookieId     The ID of the created cookie
     * @param string $cookieName   The name of the created cookie
     * @param string $cookiePrice  Decimal price string for the created cookie
     * @param int    $initialStock The initial stock quantity
     */
    public function __construct(
        public int $cookieId,
        public string $cookieName,
        public string $cookiePrice,
        public int $initialStock
    ) {
    }
}

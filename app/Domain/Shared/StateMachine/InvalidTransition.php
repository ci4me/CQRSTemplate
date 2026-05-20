<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Raised by {@see StateMachine::transition()} when a caller asks for a
 * move that is not declared in the transition table (D5).
 *
 * Carries the entity name, the rejected from/to pair, and the list of
 * legitimate targets from the current state so error messages stay
 * helpful for the API client without exposing internals.
 */
final class InvalidTransition extends DomainException
{
    /**
     * @param string       $entityName
     * @param string       $from
     * @param string       $to
     * @param list<string> $allowed
     * @return self
     */
    public static function create(
        string $entityName,
        string $from,
        string $to,
        array $allowed
    ): self {
        $allowedList = $allowed === [] ? 'none' : implode(', ', $allowed);

        return new self(
            sprintf(
                'Invalid %s transition: %s -> %s. Allowed from %s: %s.',
                $entityName,
                $from,
                $to,
                $from,
                $allowedList
            )
        );
    }
}

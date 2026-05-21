<?php

declare(strict_types=1);

namespace App\Domain\Shared\StateMachine;

/**
 * Declarative state machine helper (D5).
 *
 * Each ERP entity that has a lifecycle — Draft → Approved → Posted → Closed
 * for an invoice, Pending → Picked → Shipped → Delivered for an order,
 * etc. — should declare its allowed transitions once and let this class
 * enforce them. The same machine instance is shared across all entities of
 * the same type because the transition table is a class-level fact, not a
 * per-row one.
 *
 * Usage in an entity:
 * ```
 * private static function machine(): StateMachine
 * {
 *     return new StateMachine('Invoice', [
 *         'draft'    => ['approved', 'cancelled'],
 *         'approved' => ['posted', 'cancelled'],
 *         'posted'   => ['paid', 'voided'],
 *         'paid'     => [],
 *         'cancelled' => [],
 *         'voided'   => [],
 *     ]);
 * }
 *
 * public function approve(): void
 * {
 *     self::machine()->transition($this->status, 'approved');
 *     $this->status = 'approved';
 * }
 * ```
 *
 * The helper accepts either {@see State}-implementing objects or plain
 * strings; comparisons always happen on the stringified form.
 */
final readonly class StateMachine
{
    /**
     * @param string                      $entityName  Used in error messages.
     * @param array<string, list<string>> $transitions
     *        Map of current-state -> list of states reachable from it.
     *        Terminal states map to an empty list.
     */
    public function __construct(
        private string $entityName,
        private array $transitions
    ) {
    }

    /**
     * Throws {@see InvalidTransition} if the move is not in the table.
     *
     * @throws InvalidTransition
     */
    public function transition(State|string $from, State|string $to): void
    {
        $fromName = $this->nameOf($from);
        $toName = $this->nameOf($to);

        $allowed = $this->transitions[$fromName] ?? null;
        if ($allowed === null) {
            throw InvalidTransition::create($this->entityName, $fromName, $toName, []);
        }

        if (!in_array($toName, $allowed, true)) {
            throw InvalidTransition::create($this->entityName, $fromName, $toName, $allowed);
        }
    }

    /**
     * canTransition.
     */
    public function canTransition(State|string $from, State|string $to): bool
    {
        try {
            $this->transition($from, $to);
            return true;
        } catch (InvalidTransition) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function allowedFrom(State|string $from): array
    {
        return $this->transitions[$this->nameOf($from)] ?? [];
    }

    /**
     * isTerminal.
     */
    public function isTerminal(State|string $state): bool
    {
        return $this->allowedFrom($state) === [];
    }

    /**
     * nameOf.
     */
    private function nameOf(State|string $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        return $value->stateName();
    }
}

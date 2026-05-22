<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Cookie stock (immutable value object).
 *
 * Encapsulates the stock-quantity invariants for the Cookie aggregate:
 * stock is a non-negative integer, decrement may not go below zero, and
 * increment/decrement quantities must themselves be positive.
 *
 * Phase 4 split: was inline state + validation on the Cookie entity. Moving
 * it here keeps Cookie focused on lifecycle + event emission and gives the
 * stock rules a single self-contained symbol that AI agents and humans can
 * reason about in isolation.
 *
 * Usage:
 *   $stock = CookieStock::fromInt(50);
 *   $stock = $stock->decrementBy(10);   // -> CookieStock(40)
 *   $stock = $stock->incrementBy(5);    // -> CookieStock(45)
 *   $stock->value();                    // 45
 *   $stock->isOutOfStock();             // false
 */
final readonly class CookieStock
{
    private function __construct(private int $value)
    {
    }

    /**
     * @throws ValidationException
     */
    public static function fromInt(int $value): self
    {
        if ($value < 0) {
            throw ValidationException::tooSmall('stock', 0, $value, ErrorCodes::COOKIE_VALIDATION_STOCK);
        }

        return new self($value);
    }

    /**
     * Current stock level.
     *
     * Encapsulated accessor (slice 02/F8): the underlying `$value`
     * property is private — symmetric with {@see CookieName} and
     * {@see CookiePrice} — so the only way out is through this method.
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * @throws ValidationException
     * @throws DomainException
     */
    public function decrementBy(int $quantity): self
    {
        $this->assertPositiveQuantity($quantity);

        $newValue = $this->value - $quantity;
        if ($newValue < 0) {
            throw DomainException::businessRuleViolation(
                'Stock cannot be negative',
                sprintf('Attempted to decrease stock by %d when only %d available', $quantity, $this->value),
                ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE
            );
        }

        return new self($newValue);
    }

    /**
     * @throws ValidationException
     */
    public function incrementBy(int $quantity): self
    {
        $this->assertPositiveQuantity($quantity);

        return new self($this->value + $quantity);
    }

    public function isOutOfStock(): bool
    {
        return $this->value === 0;
    }

    /**
     * @throws ValidationException
     */
    private function assertPositiveQuantity(int $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ValueObjects;

/**
 * Domain-meaningful taxonomy for the cause of a stock movement.
 *
 * Replaces the stringly-typed `reason` field that
 * {@see \App\Domain\Cookie\Entities\Cookie::changeStock()} previously
 * passed verbatim from the entry-point method name (`'decreaseStock'` /
 * `'increaseStock'`). Round-3 audit slice 01/F9 flagged the original as
 * a copy-paste hazard: a cloned `Order::cancel()` would inherit the same
 * "method name as reason" anti-pattern, and downstream analytics could
 * never group movements by *intent* (sale vs restock vs adjustment).
 *
 * The string backing values are SCREAMING_SNAKE_CASE so audit logs and
 * BI dashboards have stable, human-readable identifiers that survive
 * code rename refactors of the underlying case name.
 *
 * Future cases can be added without breaking existing consumers — adding
 * an enum case is additive. Renaming a case IS a breaking change for
 * audit consumers and should be treated as a schema migration.
 *
 * @package App\Domain\Cookie\ValueObjects
 */
enum StockChangeReason: string
{
    case Sale = 'SALE';
    case Restock = 'RESTOCK';
    case Return_ = 'RETURN';
    case Adjustment = 'ADJUSTMENT';
    case InitialStock = 'INITIAL_STOCK';
}

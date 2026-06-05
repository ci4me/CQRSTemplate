<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Shared\Aggregate\AggregateHydrator;
use App\Domain\Shared\Aggregate\AggregateRootInterface;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;

/**
 * Cookie Domain Entity (Aggregate Root).
 *
 * This entity represents a cookie product and orchestrates the cookie
 * lifecycle (create, update, delete, restore, stock movement) while
 * delegating the granular invariants to dedicated value objects:
 *  - {@see CookieName}   - name validation
 *  - {@see CookiePrice}  - price + currency + minor-unit math
 *  - {@see CookieStock}  - non-negative stock + increment/decrement rules
 *
 * Business Rules Enforced (directly or via VOs):
 * 1. Cookie name must be unique (enforced by repository)
 * 2. Price must be greater than zero (CookiePrice)
 * 3. Stock cannot be negative (CookieStock)
 * 4. Inactive cookies cannot be displayed to customers
 * 5. Deleted cookies are soft-deleted (deleted_at field)
 *
 * Event-emission convention (round-4 R1 — single dispatch path):
 * - The AGGREGATE raises every lifecycle event through the AggregateRoot
 *   trait: update() -> CookieUpdatedEvent, markDeleted() -> CookieDeletedEvent,
 *   restore() -> CookieRestoredEvent, changeStock() -> CookieStockChangedEvent,
 *   and recordCreation() -> CookieCreatedEvent (invoked by the repository
 *   right after the freshly-allocated id is assigned).
 * - The REPOSITORY is the single drain point: it writes drained events to
 *   the outbox in the same transaction as the entity write, then (when a
 *   dispatcher is wired) dispatches synchronously and marks the rows
 *   delivered. Handlers never construct or dispatch events.
 *
 * Hydration contract:
 * - {@see assignId()} and {@see bumpVersion()} require an
 *   {@see AggregateHydrator} key parameter so casual callers (a
 *   controller, a test helper) cannot drive the entity's identity /
 *   version surface. The key is minted via {@see AggregateHydrator::key()}
 *   and a future PHPStan rule (E05.5) will restrict who may call it.
 * - {@see reconstitute()} rejects `version < 1` to catch malformed DB
 *   rows or migration drift before they silently neuter optimistic
 *   locking (any persisted row has had at least one write).
 *
 * @package App\Domain\Cookie\Entities
 */
final class Cookie implements AggregateRootInterface
{
    use AggregateRoot;
    use CookieAccessors;

    private ?int $id = null;
    private int $version = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;

    private function __construct(
        private CookieName $name,
        private ?string $description,
        private CookiePrice $price,
        private CookieStock $stock,
        private bool $isActive = true
    ) {
    }

    /**
     * Create a new Cookie (factory method for new cookies).
     *
     * @throws ValidationException
     */
    public static function create(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive = true
    ): self {
        return new self($name, $description, $price, CookieStock::fromInt($stock), $isActive);
    }

    /**
     * Reconstitute a Cookie from persistence.
     *
     * The `$version` argument MUST be >= 1: any row that survived a
     * round-trip through the repository has been written at least once
     * and therefore has a version >= 1 (see `performSave` in the
     * repository, which bumps from 0 to 1 on first insert). Accepting
     * `version = 0` here would silently neuter optimistic locking on the
     * next update (the WHERE clause matches whatever row has version 0,
     * not the row we loaded). Fail loud instead, so a corrupted DB row or
     * a migration that forgot to backfill the column surfaces immediately.
     *
     * @throws \InvalidArgumentException When `$version < 1`
     * @throws ValidationException
     */
    public static function reconstitute(
        int $id,
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive,
        ?string $createdAt,
        ?string $updatedAt,
        ?string $deletedAt,
        int $version
    ): self {
        if ($version < 1) {
            throw new \InvalidArgumentException(sprintf(
                'Persisted Cookie must have version >= 1; got %d — likely a malformed DB row or migration drift.',
                $version
            ));
        }

        $cookie = new self($name, $description, $price, CookieStock::fromInt($stock), $isActive);
        $cookie->id = $id;
        $cookie->createdAt = $createdAt;
        $cookie->updatedAt = $updatedAt;
        $cookie->deletedAt = $deletedAt;
        $cookie->version = $version;

        return $cookie;
    }

    /**
     * Bump the optimistic-locking version after a successful persist.
     *
     * Requires a hydration key (see class docblock + {@see AggregateHydrator}).
     * The parameter is the security contract, not a value — it exists to
     * make accidental external calls (`$cookie->bumpVersion()` from a
     * controller) impossible: the caller must explicitly mint an
     * `AggregateHydrator::key()`, which a future PHPStan rule (E05.5)
     * restricts to the repository namespace.
     *
     * @param AggregateHydrator $key Permission token; pass `AggregateHydrator::key()`
     */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- $key is the security contract, not a value
    public function bumpVersion(AggregateHydrator $key): void
    {
        $this->version++;
    }

    /**
     * Hydrate the entity with its database id after a successful insert.
     *
     * Requires a hydration key (see class docblock + {@see AggregateHydrator}).
     * Re-assigning to a different id is refused — once an aggregate has
     * been identified by the DB, that identity is part of the entity's
     * invariants.
     *
     * @param int               $id  The freshly-allocated database id
     * @param AggregateHydrator $key Permission token; pass `AggregateHydrator::key()`
     * @throws \LogicException When the entity already has a different id
     */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- $key is the security contract, not a value
    public function assignId(int $id, AggregateHydrator $key): void
    {
        if ($this->id !== null && $this->id !== $id) {
            throw new \LogicException(
                sprintf('Cookie already has id %d; refusing to reassign to %d', $this->id, $id)
            );
        }
        $this->id = $id;
    }

    /**
     * Update cookie information.
     *
     * @throws DomainException If the cookie is soft-deleted
     * @throws ValidationException If stock is negative
     */
    public function update(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive
    ): void {
        $this->assertNotDeleted();

        $previousState = $this->snapshot();
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->stock = CookieStock::fromInt($stock);
        $this->isActive = $isActive;

        if ($this->id === null) {
            return;
        }

        $this->raiseEvent(new CookieUpdatedEvent(
            cookieId: $this->id,
            cookieName: $name->getValue(),
            cookiePrice: $price->toDecimalString(),
            previousState: $previousState,
            newState: $this->snapshot()
        ));
    }

    /**
     * @return array<string, scalar|null>
     */
    private function snapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name->getValue(),
            'description' => $this->description,
            'price' => $this->price->toDecimalString(),
            'stock' => $this->stock->value,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @throws DomainException
     */
    private function assertNotDeleted(): void
    {
        if ($this->deletedAt !== null) {
            throw DomainException::invalidState(
                'Cookie',
                'cannot mutate a soft-deleted cookie; restore it first',
                ErrorCodes::COOKIE_STATE_DELETED
            );
        }
    }

    /**
     * @throws DomainException
     */
    private function assertPersisted(string $operation): void
    {
        if ($this->id === null) {
            throw DomainException::invalidState(
                'Cookie',
                sprintf('%s requires a persisted entity (id is null)', $operation),
                ErrorCodes::COOKIE_STATE_NOT_PERSISTED
            );
        }
    }

    /**
     * Decrease stock by a given quantity.
     *
     * @param int    $quantity How many units to remove (> 0)
     * @param string $reason   Business reason for the movement (e.g. "sale",
     *                         "shrinkage") — flows into CookieStockChangedEvent
     * @throws ValidationException
     * @throws DomainException If resulting stock would be negative
     */
    public function decreaseStock(int $quantity, string $reason = 'manual_decrease'): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('decreaseStock');
        $this->changeStock($this->stock->decrementBy($quantity), $reason);
    }

    /**
     * Increase stock by a given quantity.
     *
     * @param int    $quantity How many units to add (> 0)
     * @param string $reason   Business reason for the movement (e.g.
     *                         "restock_received", "return")
     * @throws ValidationException If quantity is not positive
     */
    public function increaseStock(int $quantity, string $reason = 'manual_increase'): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('increaseStock');
        $this->changeStock($this->stock->incrementBy($quantity), $reason);
    }

    private function changeStock(CookieStock $newStock, string $reason): void
    {
        $previous = $this->stock->value;
        $this->stock = $newStock;

        $this->raiseEvent(new CookieStockChangedEvent(
            cookieId: (int) $this->id,
            previousStock: $previous,
            newStock: $newStock->value,
            reason: $reason
        ));
    }

    /**
     * Record the creation event once the repository has assigned the id.
     *
     * CookieCreatedEvent needs the freshly-allocated database id, so it
     * cannot be raised inside {@see self::create()}. The repository calls
     * this right after {@see self::assignId()} on the insert path; the
     * event then drains through the same outbox-first path as every other
     * lifecycle event. Hydrator-gated so handlers/controllers cannot fake
     * a creation record.
     *
     * @param AggregateHydrator $key   Permission token; pass `AggregateHydrator::key()`
     * @param Actor|null        $actor Who created the cookie — flows into the
     *                                 event's actorId so the E04 audit trail
     *                                 covers the creation path too
     * @throws DomainException When the entity has no id yet
     */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- $key is the security contract, not a value
    public function recordCreation(AggregateHydrator $key, ?Actor $actor = null): void
    {
        $this->assertPersisted('recordCreation');

        $this->raiseEvent(new CookieCreatedEvent(
            cookieId: (int) $this->id,
            cookieName: $this->name->getValue(),
            cookiePrice: $this->price->toDecimalString(),
            initialStock: $this->stock->value,
            actorId: $actor?->id
        ));
    }

    /**
     * Soft-delete the aggregate: record the final snapshot and raise
     * CookieDeletedEvent. The repository persists the `deleted_at` flip
     * and drains the event in the same transaction.
     *
     * @param Actor|null $actor Who initiated the delete (0/system when null)
     * @throws DomainException When not persisted or already deleted
     */
    public function markDeleted(?Actor $actor = null): void
    {
        $this->assertPersisted('markDeleted');
        $this->assertNotDeleted();

        $snapshot = $this->snapshot();
        $this->deletedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->raiseEvent(new CookieDeletedEvent(
            cookieId: (int) $this->id,
            cookieName: $this->name->getValue(),
            snapshot: $snapshot,
            deletedBy: $actor->id ?? 0
        ));
    }

    /**
     * Bring a soft-deleted aggregate back to life and raise
     * CookieRestoredEvent. Restoring a live cookie is a business-rule
     * violation (COOKIE_STATE_NOT_DELETED) — restore is not idempotent
     * by design, so a double-submit surfaces instead of silently passing.
     *
     * @param Actor|null $actor Who initiated the restore (0/system when null)
     * @throws DomainException When not persisted or not currently deleted
     */
    public function restore(?Actor $actor = null): void
    {
        $this->assertPersisted('restore');

        if ($this->deletedAt === null) {
            throw DomainException::businessRuleViolation(
                'Cookie is not deleted; nothing to restore.',
                (string) $this->id,
                ErrorCodes::COOKIE_STATE_NOT_DELETED
            );
        }

        $this->deletedAt = null;

        $this->raiseEvent(new CookieRestoredEvent(
            cookieId: (int) $this->id,
            restoredBy: $actor->id ?? 0,
            restoredAt: (new \DateTimeImmutable())->format('c')
        ));
    }

    public function activate(): void
    {
        $this->assertNotDeleted();
        $this->isActive = true;
    }

    public function deactivate(): void
    {
        $this->assertNotDeleted();
        $this->isActive = false;
    }
}

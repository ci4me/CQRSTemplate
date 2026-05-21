<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Cookie Domain Entity (Aggregate Root).
 *
 * This entity represents a cookie product in the system and enforces
 * all business rules related to cookies.
 *
 * Business Rules Enforced:
 * 1. Cookie name must be unique (enforced by repository)
 * 2. Price must be greater than zero
 * 3. Stock cannot be negative
 * 4. Inactive cookies cannot be displayed to customers
 * 5. Deleted cookies are soft-deleted (deleted_at field)
 *
 * Aggregate Root:
 * Cookie is an aggregate root in DDD terms, meaning it's the entry
 * point for all operations on the cookie aggregate. All changes
 * to a cookie go through this entity's methods.
 *
 * Event-emission convention:
 * - The entity raises CookieStockChangedEvent / CookieUpdatedEvent /
 *   CookieDeletedEvent / CookieRestoredEvent through the AggregateRoot
 *   trait; the {@see \App\Domain\Cookie\Repositories\CookieRepository} drains them
 *   after a successful save.
 * - CookieCreatedEvent is dispatched by the create handler (NOT the
 *   entity) because the event payload includes the freshly-allocated
 *   primary key, which only exists after `$repository->save()` returns.
 *   Moving it into the entity would require either an in-memory id
 *   placeholder (fragile) or post-save mutation (clashes with the
 *   immutability story). Keeping it in the handler is the simpler
 *   trade-off and is explicitly documented here so it doesn't read as
 *   an oversight.
 *
 * Why Domain Entity vs Data Model:
 * - Contains business logic and invariants
 * - Uses Value Objects for validation
 * - Immutable (use methods to create new states)
 * - Technology-agnostic (no database concerns)
 *
 * Usage Example:
 * ```php
 * $cookie = Cookie::create(
 *     name: CookieName::fromString('Chocolate Chip'),
 *     description: 'Classic recipe',
 *     price: CookiePrice::fromString('2.99'),
 *     stock: 50
 * );
 * $cookie->decreaseStock(10); // Sell 10 cookies
 * ```
 *
 * @package App\Domain\Cookie\Entities
 */
final class Cookie
{
    use AggregateRoot;

    private ?int $id = null;
    private CookieName $name;
    private ?string $description;
    private CookiePrice $price;
    private int $stock;
    private bool $isActive;
    /**
     * Optimistic-locking token. Incremented by the repository on every save;
     * UPDATEs include `WHERE version = $version` so concurrent writers detect
     * the race instead of silently overwriting each other.
     */
    private int $version = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;

    /**
     * Create a new Cookie instance.
     *
     * Use named static factories (create, reconstitute) instead of
     * calling this constructor directly.
     *
     * @param CookieName  $name        The cookie name
     * @param string|null $description The cookie description
     * @param CookiePrice $price       The cookie price
     * @param int         $stock       The stock quantity
     * @param bool        $isActive    Whether the cookie is active
     */
    private function __construct(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive = true
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->setStock($stock);
        $this->isActive = $isActive;
    }

    /**
     * Create a new Cookie (factory method for new cookies).
     *
     * @param CookieName  $name        The cookie name
     * @param string|null $description The cookie description
     * @param CookiePrice $price       The cookie price
     * @param int         $stock       The initial stock quantity
     * @param bool        $isActive    Whether the cookie is active
     */
    public static function create(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive = true
    ): self {
        return new self($name, $description, $price, $stock, $isActive);
    }

    /**
     * Reconstitute a Cookie from persistence (factory method for existing cookies).
     *
     * Used by the repository when loading cookies from the database.
     *
     * @param int         $id          The cookie ID
     * @param CookieName  $name        The cookie name
     * @param string|null $description The cookie description
     * @param CookiePrice $price       The cookie price
     * @param int         $stock       The stock quantity
     * @param bool        $isActive    Whether the cookie is active
     * @param string|null $createdAt   Creation timestamp
     * @param string|null $updatedAt   Last update timestamp
     * @param string|null $deletedAt   Deletion timestamp (null if not deleted)
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
        $cookie = new self($name, $description, $price, $stock, $isActive);
        $cookie->id = $id;
        $cookie->createdAt = $createdAt;
        $cookie->updatedAt = $updatedAt;
        $cookie->deletedAt = $deletedAt;
        $cookie->version = $version;

        return $cookie;
    }

    /**
     * Bump the optimistic-locking version after a successful persist.
     * Called by the repository — should not be called by application code.
     *
     * @internal
     */
    public function bumpVersion(): void
    {
        $this->version++;
    }

    /**
     * Hydrate the entity with its database id after a successful insert.
     * Called by the repository — should not be called by application code.
     *
     * @internal
     * @throws \LogicException
     */
    public function assignId(int $id): void
    {
        if ($this->id !== null && $this->id !== $id) {
            throw new \LogicException(
                sprintf('Cookie already has id %d; refusing to reassign to %d', $this->id, $id)
            );
        }
        $this->id = $id;
    }

    /**
     * getVersion.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Update cookie information.
     *
     * @param CookieName  $name        The new cookie name
     * @param string|null $description The new description
     * @param CookiePrice $price       The new price
     * @param int         $stock       The new stock quantity
     * @param bool        $isActive    Whether the cookie is active
     */
    public function update(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive
    ): void {
        // INVARIANT: a deleted (soft-deleted) cookie is not mutable. The
        // restore path explicitly clears deleted_at before issuing further
        // updates. Without this guard, a stale view + a slow race could
        // resurrect a deleted row by writing new field values that bypass
        // the lifecycle.
        $this->assertNotDeleted();

        // Snapshot before mutation so the event payload carries a
        // structured diff for the audit log. The handler used to do this
        // by hand; raising on the entity keeps the diff close to the
        // state transition and removes the "dispatch on success" coupling
        // from the handler.
        $previousState = $this->snapshot();

        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->setStock($stock);
        $this->isActive = $isActive;

        // Only enqueue an UpdatedEvent when the entity actually exists
        // (a fresh entity that hasn't been saved yet should fire a
        // CreatedEvent from the handler, not an UpdatedEvent). The
        // repository will drain whatever lands in the buffer.
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
            'stock' => $this->stock,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * assertNotDeleted.
     *
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
     * Refuse stock movements on an in-memory (pre-save) entity.
     *
     * Without this guard, decreaseStock/increaseStock would raise a
     * CookieStockChangedEvent whose `cookieId` is null — the event
     * lands in the outbox / dispatcher unroutable. The right shape is
     * "save first, then move stock"; if a caller has a use case for
     * pre-save stock manipulation it must be expressed as the entity's
     * initial `stock` argument to {@see self::create()}.
     *
     * @throws DomainException
     */
    private function assertPersisted(string $operation): void
    {
        if ($this->id === null) {
            throw DomainException::invalidState(
                'Cookie',
                sprintf('%s requires a persisted entity (id is null)', $operation),
                ErrorCodes::COOKIE_STATE_DELETED
            );
        }
    }

    /**
     * Decrease stock by a given quantity.
     *
     * Business Rule: Stock cannot go negative.
     *
     * @param int $quantity The quantity to decrease
     * @throws ValidationException
     * @throws DomainException If resulting stock would be negative
     */
    public function decreaseStock(int $quantity): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('decreaseStock');

        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }

        $newStock = $this->stock - $quantity;

        if ($newStock < 0) {
            throw DomainException::businessRuleViolation(
                'Stock cannot be negative',
                sprintf('Attempted to decrease stock by %d when only %d available', $quantity, $this->stock),
                ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE
            );
        }

        $previous = $this->stock;
        $this->stock = $newStock;

        $this->raiseEvent(new CookieStockChangedEvent(
            cookieId: $this->id,
            previousStock: $previous,
            newStock: $newStock,
            reason: 'decreaseStock'
        ));
    }

    /**
     * Increase stock by a given quantity.
     *
     * @param int $quantity The quantity to increase
     * @throws ValidationException If quantity is not positive
     */
    public function increaseStock(int $quantity): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('increaseStock');

        if ($quantity <= 0) {
            throw ValidationException::tooSmall('quantity', 1, $quantity);
        }

        $previous = $this->stock;
        $this->stock += $quantity;

        $this->raiseEvent(new CookieStockChangedEvent(
            cookieId: $this->id,
            previousStock: $previous,
            newStock: $this->stock,
            reason: 'increaseStock'
        ));
    }

    /**
     * Set stock to a specific value.
     *
     * Business Rule: Stock cannot be negative.
     *
     * @param int $stock The new stock quantity
     * @throws ValidationException If stock is negative
     */
    private function setStock(int $stock): void
    {
        if ($stock < 0) {
            throw ValidationException::tooSmall('stock', 0, $stock, ErrorCodes::COOKIE_VALIDATION_STOCK);
        }

        $this->stock = $stock;
    }

    /**
     * Activate the cookie (make it visible to customers).
     */
    public function activate(): void
    {
        $this->assertNotDeleted();
        $this->isActive = true;
    }

    /**
     * Deactivate the cookie (hide from customers).
     */
    public function deactivate(): void
    {
        $this->assertNotDeleted();
        $this->isActive = false;
    }

    /**
     * Check if the cookie is available for purchase.
     *
     * A cookie is available if it's active, not deleted, and has stock.
     *
     * @return bool True if available
     */
    public function isAvailable(): bool
    {
        return $this->isActive && $this->deletedAt === null && $this->stock > 0;
    }

    /**
     * Check if the cookie is out of stock.
     *
     * @return bool True if out of stock
     */
    public function isOutOfStock(): bool
    {
        return $this->stock === 0;
    }

    /**
     * Check if the cookie is deleted (soft delete).
     *
     * @return bool True if deleted
     */
    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    // Getters

    /**
     * getId.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * getName.
     */
    public function getName(): CookieName
    {
        return $this->name;
    }

    /**
     * getDescription.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * getPrice.
     */
    public function getPrice(): CookiePrice
    {
        return $this->price;
    }

    /**
     * getStock.
     */
    public function getStock(): int
    {
        return $this->stock;
    }

    /**
     * getIsActive.
     */
    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    /**
     * getCreatedAt.
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    /**
     * getUpdatedAt.
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * getDeletedAt.
     */
    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }
}

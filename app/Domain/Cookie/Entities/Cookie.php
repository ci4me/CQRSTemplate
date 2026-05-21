<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;

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
 * Event-emission convention:
 * - The entity raises CookieStockChangedEvent / CookieUpdatedEvent
 *   through the AggregateRoot trait; the repository drains them after
 *   a successful save.
 * - CookieCreatedEvent is dispatched by the create handler (not the
 *   entity) because the event payload needs the freshly-allocated id.
 *
 * @package App\Domain\Cookie\Entities
 */
final class Cookie
{
    use AggregateRoot;
    use CookieAccessors;

    private ?int $id = null;
    private CookieName $name;
    private ?string $description;
    private CookiePrice $price;
    private CookieStock $stock;
    private bool $isActive;
    private int $version = 0;
    private ?string $createdAt = null;
    private ?string $updatedAt = null;
    private ?string $deletedAt = null;

    private function __construct(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        CookieStock $stock,
        bool $isActive = true
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->price = $price;
        $this->stock = $stock;
        $this->isActive = $isActive;
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
     * @internal
     */
    public function bumpVersion(): void
    {
        $this->version++;
    }

    /**
     * Hydrate the entity with its database id after a successful insert.
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

    public function getVersion(): int
    {
        return $this->version;
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
                ErrorCodes::COOKIE_STATE_DELETED
            );
        }
    }

    /**
     * Decrease stock by a given quantity.
     *
     * @throws ValidationException
     * @throws DomainException If resulting stock would be negative
     */
    public function decreaseStock(int $quantity): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('decreaseStock');
        $this->changeStock($this->stock->decrementBy($quantity), 'decreaseStock');
    }

    /**
     * Increase stock by a given quantity.
     *
     * @throws ValidationException If quantity is not positive
     */
    public function increaseStock(int $quantity): void
    {
        $this->assertNotDeleted();
        $this->assertPersisted('increaseStock');
        $this->changeStock($this->stock->incrementBy($quantity), 'increaseStock');
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

    public function isAvailable(): bool
    {
        return $this->isActive && $this->deletedAt === null && ! $this->stock->isOutOfStock();
    }

    public function isOutOfStock(): bool
    {
        return $this->stock->isOutOfStock();
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}

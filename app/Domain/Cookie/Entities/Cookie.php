<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Shared\Aggregate\AggregateHydrator;
use App\Domain\Shared\Aggregate\AggregateRootInterface;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\CookieChangeSet;
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
            eventId: AbstractDomainEvent::newId(),
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            actorId: null, // E07 will thread the acting user through the entity.
            cookieId: $this->id,
            cookieName: $name->getValue(),
            cookiePrice: $price->toDecimalString(),
            previousState: $previousState,
            newState: $this->snapshot(),
        ));
    }

    /**
     * Build a {@see CookieChangeSet} snapshot of the entity's current
     * whitelisted public state. The change set replaces the loose
     * `array<string, scalar|null>` snapshots flagged in slice 05/F4.
     *
     * Note: `price` is decomposed into `price_minor` / `price_currency`
     * to match the change-set whitelist (E09 will land the wider
     * multi-currency schema; until then `USD` is the implicit default).
     */
    private function snapshot(): CookieChangeSet
    {
        return CookieChangeSet::fromArray([
            'id' => $this->id,
            'name' => $this->name->getValue(),
            'description' => $this->description,
            'price_minor' => $this->price->getMinorUnits(),
            'price_currency' => $this->price->getCurrency()->iso,
            'stock' => $this->stock->value,
            'is_active' => $this->isActive,
            'version' => $this->version,
            'deleted_at' => $this->deletedAt,
        ]);
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

        // assertPersisted() above the public entry points guarantees $this->id
        // is set by the time we reach this method, so the (int) cast is a
        // type-narrowing no-op rather than masking a nullable.
        $this->raiseEvent(new CookieStockChangedEvent(
            eventId: AbstractDomainEvent::newId(),
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            actorId: null, // E07 will thread the acting user through the entity.
            cookieId: (int) $this->id,
            previousStock: $previous,
            newStock: $newStock->value,
            reason: $reason,
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

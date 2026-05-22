<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieActivated\CookieActivatedEvent;
use App\Domain\Cookie\Events\CookieDeactivated\CookieDeactivatedEvent;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieSnapshot;
use App\Domain\Cookie\ValueObjects\CookieStock;
use App\Domain\Cookie\ValueObjects\StockChangeReason;
use App\Domain\Shared\Aggregate\AggregateHydrator;
use App\Domain\Shared\Aggregate\AggregateRootInterface;
use App\Domain\Shared\AggregateRoot;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;

/**
 * Cookie Domain Entity (Aggregate Root).
 *
 * Owns the Cookie lifecycle: create, update, soft-delete, restore,
 * activate, deactivate, stock movement. Invariants delegate to value
 * objects ({@see CookieName}, {@see CookiePrice}, {@see CookieStock});
 * preconditions live in {@see CookieStateAssertions}.
 *
 * Event-emission convention (E07): every public mutator raises ≥ 1
 * event through the AggregateRoot bag. The repository drains the bag
 * post-persist. CookieCreatedEvent stays handler-side because it needs
 * the freshly-allocated id; E08 unifies the remaining handlers.
 *
 * Hydration contract (E06): {@see assignId()} and {@see bumpVersion()}
 * require an {@see AggregateHydrator} key; {@see reconstitute()}
 * rejects `version < 1` (slice 01/F4).
 *
 * @package App\Domain\Cookie\Entities
 */
final class Cookie implements AggregateRootInterface
{
    use AggregateRoot;

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

    /** @throws ValidationException */
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
     * @throws \InvalidArgumentException When `$version < 1`.
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

    // Accessors (inlined from former CookieAccessors trait, slice 01/F8).
    #[\Override]
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getName(): CookieName
    {
        return $this->name;
    }
    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function getPrice(): CookiePrice
    {
        return $this->price;
    }
    public function getStock(): int
    {
        return $this->stock->value;
    }
    public function getIsActive(): bool
    {
        return $this->isActive;
    }
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
    public function getDeletedAt(): ?string
    {
        return $this->deletedAt;
    }
    public function getVersion(): int
    {
        return $this->version;
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

    // Hydration contract (E06): $key is the security parameter.
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- $key is the security contract
    public function bumpVersion(AggregateHydrator $key): void
    {
        $this->version++;
    }

    /** @throws \LogicException When the entity already has a different id. */
    // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- $key is the security contract
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
     * @throws DomainException     If the cookie is soft-deleted.
     * @throws ValidationException If stock is negative.
     */
    public function update(
        CookieName $name,
        ?string $description,
        CookiePrice $price,
        int $stock,
        bool $isActive
    ): void {
        CookieStateAssertions::ensureNotDeleted($this->deletedAt);
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
            occurredAt: $this->nowUtc(),
            actorId: null, // E08 will thread the acting user through commands.
            cookieId: $this->id,
            cookieName: $name->getValue(),
            cookiePrice: $price->toDecimalString(),
            previousState: $previousState->toChangeSet(),
            newState: $this->snapshot()->toChangeSet(),
        ));
    }

    /** @throws DomainException When already deleted or pre-persist. */
    public function softDelete(?int $actorId = null): void
    {
        CookieStateAssertions::ensureNotDeleted($this->deletedAt);
        $id = CookieStateAssertions::ensurePersisted($this->id, 'softDelete');
        $snapshot = $this->snapshot();
        $this->deletedAt = $this->nowUtc()->format('Y-m-d H:i:s');
        $this->raiseEvent(new CookieDeletedEvent(
            eventId: AbstractDomainEvent::newId(),
            occurredAt: $this->nowUtc(),
            actorId: $actorId,
            cookieId: $id,
            cookieName: $this->name->getValue(),
            snapshot: $snapshot->toChangeSet(),
        ));
    }

    /** @throws DomainException When not deleted or pre-persist. */
    public function restore(?int $actorId = null): void
    {
        $id = CookieStateAssertions::ensurePersisted($this->id, 'restore');
        if ($this->deletedAt === null) {
            throw DomainException::invalidState(
                'Cookie',
                'cannot restore a cookie that is not deleted',
                ErrorCodes::COOKIE_STATE_NOT_DELETED
            );
        }
        $this->deletedAt = null;
        $this->raiseEvent($this->buildLifecycleEvent(CookieRestoredEvent::class, $id, $actorId));
    }

    /** Idempotent: a no-op if already active. @throws DomainException */
    public function activate(?int $actorId = null): void
    {
        $this->setActive(true, CookieActivatedEvent::class, 'activate', $actorId);
    }

    /** Idempotent: a no-op if already inactive. @throws DomainException */
    public function deactivate(?int $actorId = null): void
    {
        $this->setActive(false, CookieDeactivatedEvent::class, 'deactivate', $actorId);
    }

    /**
     * @param class-string<CookieActivatedEvent|CookieDeactivatedEvent> $eventClass
     * @throws DomainException
     */
    private function setActive(bool $next, string $eventClass, string $operation, ?int $actorId): void
    {
        CookieStateAssertions::ensureNotDeleted($this->deletedAt);
        $id = CookieStateAssertions::ensurePersisted($this->id, $operation);
        if ($this->isActive === $next) {
            return;
        }
        $this->isActive = $next;
        $this->raiseEvent($this->buildLifecycleEvent($eventClass, $id, $actorId));
    }

    /**
     * @param class-string<CookieActivatedEvent|CookieDeactivatedEvent|CookieRestoredEvent> $eventClass
     */
    private function buildLifecycleEvent(
        string $eventClass,
        int $cookieId,
        ?int $actorId
    ): CookieActivatedEvent|CookieDeactivatedEvent|CookieRestoredEvent {
        return new $eventClass(
            eventId: AbstractDomainEvent::newId(),
            occurredAt: $this->nowUtc(),
            actorId: $actorId,
            cookieId: $cookieId,
        );
    }

    /** @throws ValidationException @throws DomainException */
    public function decreaseStock(int $quantity, StockChangeReason $reason = StockChangeReason::Sale): void
    {
        CookieStateAssertions::ensureNotDeleted($this->deletedAt);
        CookieStateAssertions::ensurePersisted($this->id, 'decreaseStock');
        $this->changeStock($this->stock->decrementBy($quantity), $reason);
    }

    /** @throws ValidationException @throws DomainException */
    public function increaseStock(int $quantity, StockChangeReason $reason = StockChangeReason::Restock): void
    {
        CookieStateAssertions::ensureNotDeleted($this->deletedAt);
        CookieStateAssertions::ensurePersisted($this->id, 'increaseStock');
        $this->changeStock($this->stock->incrementBy($quantity), $reason);
    }

    private function snapshot(): CookieSnapshot
    {
        return CookieSnapshot::fromArray([
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

    private function changeStock(CookieStock $newStock, StockChangeReason $reason): void
    {
        $previous = $this->stock->value;
        $this->stock = $newStock;
        \assert($this->id !== null, 'changeStock is gated by ensurePersisted()');
        $this->raiseEvent(new CookieStockChangedEvent(
            eventId: AbstractDomainEvent::newId(),
            occurredAt: $this->nowUtc(),
            actorId: null, // E08 will thread the acting user through commands.
            cookieId: $this->id,
            previousStock: $previous,
            newStock: $newStock->value,
            reason: $reason,
        ));
    }

    private function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Cookie\ReadModels;

use App\Domain\Cookie\Entities\Cookie;

/**
 * Read-model DTO for the Cookie aggregate (B14).
 *
 * Why a DTO instead of returning the entity directly:
 * - Decouples the read shape from the write model. The entity can grow new
 *   internal invariants without forcing API/view consumers to follow.
 * - Lets each surface (API JSON, web view, search index) shape data
 *   differently without polluting the entity.
 * - Serialisable without leaking entity internals (no aggregate events,
 *   no version, no infrastructure concerns).
 *
 * Two surfaces, two DTOs:
 * - {@see CookieView::detail()} — single-resource detail page / GET /cookies/{id}
 * - {@see CookieView::summary()} — list/index rows. Smaller payload; omits
 *   description and timestamps to keep listing responses lean.
 *
 * The DTO is read-only and serialises to JSON via {@see self::toArray()};
 * the new ApiResponse envelope wraps that as `data`.
 */
final readonly class CookieView
{
    /**
     * @param array<string, scalar|null> $extra       extra fields (currently unused;
     *                                                reserved for tenant_id, audit
     *                                                fields when those land in the
     *                                                view)
     */
    private function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public int $stock,
        public bool $isActive,
        public int $version,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $deletedAt,
        public bool $isDeleted,
        public bool $isAvailable,
        public array $extra = []
    ) {
    }

    /**
     * detail.
     */
    public static function detail(Cookie $cookie): self
    {
        return new self(
            id: $cookie->getId() ?? 0,
            name: $cookie->getName()->getValue(),
            description: $cookie->getDescription(),
            price: $cookie->getPrice()->toDecimalString(),
            stock: $cookie->getStock(),
            isActive: $cookie->getIsActive(),
            version: $cookie->getVersion(),
            createdAt: $cookie->getCreatedAt(),
            updatedAt: $cookie->getUpdatedAt(),
            deletedAt: $cookie->getDeletedAt(),
            isDeleted: $cookie->isDeleted(),
            isAvailable: $cookie->isAvailable()
        );
    }

    /**
     * Lighter shape for list rendering — no description, no timestamps.
     */
    public static function summary(Cookie $cookie): self
    {
        return new self(
            id: $cookie->getId() ?? 0,
            name: $cookie->getName()->getValue(),
            description: null,
            price: $cookie->getPrice()->toDecimalString(),
            stock: $cookie->getStock(),
            isActive: $cookie->getIsActive(),
            version: $cookie->getVersion(),
            createdAt: null,
            updatedAt: null,
            deletedAt: null,
            isDeleted: $cookie->isDeleted(),
            isAvailable: $cookie->isAvailable()
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'stock' => $this->stock,
            'is_active' => $this->isActive,
            'version' => $this->version,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
            'is_deleted' => $this->isDeleted,
            'is_available' => $this->isAvailable,
        ];
    }

    /**
     * Map a list of entities to a list of summaries (B14 list shape).
     *
     * @param list<Cookie> $cookies
     * @return list<self>
     */
    public static function summarise(array $cookies): array
    {
        return array_map(static fn(Cookie $c): self => self::summary($c), $cookies);
    }
}

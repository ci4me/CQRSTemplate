<?php

declare(strict_types=1);

namespace App\Domain\Cookie\DTOs;

use App\Domain\Cookie\Entities\Cookie;

/**
 * Data Transfer Object for Cookie entity.
 *
 * Prevents domain entities from leaking into the presentation layer.
 *
 * @package App\Domain\Cookie\DTOs
 */
final readonly class CookieDTO
{
    /**
     * Construct a CookieDTO directly from already-validated read-side data.
     *
     * Usually called from {@see self::fromEntity()} or the read-side repository's
     * row-mapping path; HTTP layers should not call this constructor directly.
     *
     * @param ?int    $id             Database id; null only for entities that have not yet been persisted.
     * @param string  $name           Canonical cookie name (trimmed, validated upstream).
     * @param ?string $description    Optional long-form description.
     * @param string  $price          Decimal-string sale price (e.g. "12.50").
     * @param string  $formattedPrice Currency-formatted presentation string (e.g. "USD 12.50").
     * @param int     $stock          On-hand quantity (>= 0).
     * @param bool    $isActive       Whether the row is currently published.
     * @param ?string $createdAt      ISO-8601 timestamp; null for unpersisted entities.
     * @param ?string $updatedAt      ISO-8601 timestamp; null if the row has never been updated.
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public string $formattedPrice,
        public int $stock,
        public bool $isActive,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Build a CookieDTO from a hydrated Cookie aggregate.
     *
     * Pulls the public read-side projection of the entity (name string, decimal
     * price, formatted price, timestamps) without exposing the aggregate's
     * pending-events bag or version. Use this at the boundary between command
     * handlers and HTTP/view layers.
     */
    public static function fromEntity(Cookie $cookie): self
    {
        return new self(
            id: $cookie->getId(),
            name: $cookie->getName()->getValue(),
            description: $cookie->getDescription(),
            price: $cookie->getPrice()->toDecimalString(),
            formattedPrice: $cookie->getPrice()->format(),
            stock: $cookie->getStock(),
            isActive: $cookie->getIsActive(),
            createdAt: $cookie->getCreatedAt(),
            updatedAt: $cookie->getUpdatedAt(),
        );
    }

    /**
     * Convenience predicate for the API/view layer — true when stock is exactly zero.
     *
     * Negative stock is impossible at the entity boundary (CookieStock enforces it),
     * so the cheaper `=== 0` check is safe here. Lives on the DTO so listing views
     * can render "OUT OF STOCK" badges without re-querying the catalogue.
     */
    public function isOutOfStock(): bool
    {
        return $this->stock === 0;
    }
}

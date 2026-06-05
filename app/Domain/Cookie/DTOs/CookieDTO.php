<?php

declare(strict_types=1);

namespace App\Domain\Cookie\DTOs;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Services\PriceFormatter;
use App\Domain\Shared\DTOs\ReadDTOInterface;

/**
 * Data Transfer Object for Cookie entity (the canonical read DTO — E10).
 *
 * Prevents domain entities from leaking into the presentation layer.
 * Round-4 R3 consolidation:
 *  - `id` is non-nullable: every read-side row has an id, and the dead
 *    CookieView read model was deleted in favour of this single DTO.
 *  - `outOfStock` is a precomputed FIELD, not a method — read DTOs carry
 *    data, never behaviour.
 *  - implements {@see ReadDTOInterface}: snake_case + ISO-8601 wire shape.
 *
 * @package App\Domain\Cookie\DTOs
 */
final readonly class CookieDTO implements ReadDTOInterface
{
    /**
     * Construct a CookieDTO directly from already-validated read-side data.
     *
     * Usually called from {@see self::fromEntity()} or the read-side repository's
     * row-mapping path; HTTP layers should not call this constructor directly.
     *
     * @param int     $id             Database id of the row.
     * @param string  $name           Canonical cookie name (trimmed, validated upstream).
     * @param ?string $description    Optional long-form description.
     * @param string  $price          Decimal-string sale price (e.g. "12.50").
     * @param string  $formattedPrice Currency-formatted presentation string (e.g. "$12.50").
     * @param int     $stock          On-hand quantity (>= 0).
     * @param bool    $outOfStock     Precomputed stock === 0 flag for listing badges.
     * @param bool    $isActive       Whether the row is currently published.
     * @param ?string $createdAt      ISO-8601 timestamp; null when the source row carries none.
     * @param ?string $updatedAt      ISO-8601 timestamp; null if the row has never been updated.
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $price,
        public string $formattedPrice,
        public int $stock,
        public bool $outOfStock,
        public bool $isActive,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * Build a CookieDTO from a hydrated (PERSISTED) Cookie aggregate.
     *
     * Pulls the public read-side projection of the entity (name string, decimal
     * price, formatted price, timestamps) without exposing the aggregate's
     * pending-events bag or version. Use this at the boundary between command
     * handlers and HTTP/view layers.
     *
     * @throws \LogicException When the entity has no id (unpersisted)
     */
    public static function fromEntity(Cookie $cookie): self
    {
        $id = $cookie->getId();
        if ($id === null) {
            throw new \LogicException(
                'CookieDTO::fromEntity() requires a persisted entity — read DTOs always have an id.'
            );
        }

        return new self(
            id: $id,
            name: $cookie->getName()->getValue(),
            description: $cookie->getDescription(),
            price: $cookie->getPrice()->toDecimalString(),
            formattedPrice: PriceFormatter::format($cookie->getPrice()),
            stock: $cookie->getStock(),
            outOfStock: $cookie->getStock() === 0,
            isActive: $cookie->getIsActive(),
            createdAt: $cookie->getCreatedAt(),
            updatedAt: $cookie->getUpdatedAt(),
        );
    }

    /**
     * Snake_case wire representation (ReadDTOInterface).
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'formatted_price' => $this->formattedPrice,
            'stock' => $this->stock,
            'out_of_stock' => $this->outOfStock,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * JSON shape === toArray() shape, by contract.
     *
     * @return array<string, scalar|null>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

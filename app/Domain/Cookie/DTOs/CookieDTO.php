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
     * __construct.
     *
     * @param int|null    $id
     * @param string      $name
     * @param string|null $description
     * @param string      $price
     * @param string      $formattedPrice
     * @param int         $stock
     * @param bool        $isActive
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @todo Auto-generated docblock — review and replace this description.
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
     * fromEntity.
     *
     * @param Cookie $cookie
     * @return self
     * @todo Auto-generated docblock — review and replace this description.
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
     * isOutOfStock.
     *
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function isOutOfStock(): bool
    {
        return $this->stock === 0;
    }
}

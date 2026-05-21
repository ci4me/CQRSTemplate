<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Entities;

use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Cookie\ValueObjects\CookieStock;

/**
 * Read-only accessors for the Cookie aggregate.
 *
 * Phase 4 split: extracted from {@see Cookie} to keep the aggregate focused
 * on lifecycle and business rules. The getters are pure read operations and
 * carry no business logic, so they live in a trait the entity composes in.
 *
 * @property ?int        $id
 * @property CookieName  $name
 * @property ?string     $description
 * @property CookiePrice $price
 * @property CookieStock $stock
 * @property bool        $isActive
 * @property ?string     $createdAt
 * @property ?string     $updatedAt
 * @property ?string     $deletedAt
 */
trait CookieAccessors
{
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
}

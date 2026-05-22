<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\DTOs;

use App\Domain\Cookie\DTOs\CookieDTO;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

final class CookieDTOTest extends UnitTestCase
{
    public function test_from_entity_copies_every_field(): void
    {
        $cookie = CookieFactory::createPersistedCookie([
            'id' => 7,
            'name' => 'Snickerdoodle',
            'description' => 'cinnamon-sugar coated',
            'price' => '3.25',
            'stock' => 12,
            'isActive' => true,
        ]);

        $dto = CookieDTO::fromEntity($cookie);

        $this->assertSame(7, $dto->id);
        $this->assertSame('Snickerdoodle', $dto->name);
        $this->assertSame('cinnamon-sugar coated', $dto->description);
        $this->assertSame('3.25', $dto->price);
        $this->assertSame('$3.25', $dto->formattedPrice);
        $this->assertSame(12, $dto->stock);
        $this->assertTrue($dto->isActive);
    }

    public function test_from_entity_preserves_nullable_description(): void
    {
        $cookie = CookieFactory::createPersistedCookie(['description' => null]);

        $dto = CookieDTO::fromEntity($cookie);

        $this->assertNull($dto->description);
    }

    public function test_is_out_of_stock_returns_true_when_stock_is_zero(): void
    {
        $cookie = CookieFactory::createPersistedCookie(['stock' => 0]);

        $dto = CookieDTO::fromEntity($cookie);

        $this->assertTrue($dto->isOutOfStock());
    }

    public function test_is_out_of_stock_returns_false_when_stock_is_positive(): void
    {
        $cookie = CookieFactory::createPersistedCookie(['stock' => 1]);

        $dto = CookieDTO::fromEntity($cookie);

        $this->assertFalse($dto->isOutOfStock());
    }
}

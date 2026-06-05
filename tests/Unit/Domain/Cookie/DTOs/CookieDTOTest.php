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

    public function test_out_of_stock_is_precomputed_field(): void
    {
        // E10 (round-4 R3): read DTOs carry data, not behaviour — the old
        // isOutOfStock() method became the precomputed outOfStock field.
        $dto = CookieDTO::fromEntity(CookieFactory::createPersistedCookie(['stock' => 0]));
        $this->assertTrue($dto->outOfStock);

        $dto = CookieDTO::fromEntity(CookieFactory::createPersistedCookie(['stock' => 1]));
        $this->assertFalse($dto->outOfStock);
    }

    public function test_from_entity_requires_persisted_entity(): void
    {
        $unsaved = \App\Domain\Cookie\Entities\Cookie::create(
            name: \App\Domain\Cookie\ValueObjects\CookieName::fromString('Unsaved'),
            description: null,
            price: \App\Domain\Cookie\ValueObjects\CookiePrice::fromString('1.00'),
            stock: 1
        );

        $this->expectException(\LogicException::class);
        CookieDTO::fromEntity($unsaved);
    }

    public function test_wire_shape_is_snake_case_with_matching_json(): void
    {
        // ReadDTOInterface contract (E10): toArray() === jsonSerialize(),
        // snake_case keys at the JSON boundary.
        $dto = CookieDTO::fromEntity(CookieFactory::createPersistedCookie(['id' => 9, 'stock' => 0]));

        $wire = $dto->toArray();
        $this->assertSame(9, $wire['id']);
        $this->assertArrayHasKey('formatted_price', $wire);
        $this->assertArrayHasKey('out_of_stock', $wire);
        $this->assertArrayHasKey('is_active', $wire);
        $this->assertTrue($wire['out_of_stock']);
        $this->assertSame($wire, $dto->jsonSerialize());
        $this->assertSame(json_encode($wire), json_encode($dto));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\ReadModels;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ReadModels\CookieView;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use Tests\Support\UnitTestCase;

final class CookieViewTest extends UnitTestCase
{
    public function test_detail_view_includes_all_fields(): void
    {
        $cookie = Cookie::reconstitute(
            id: 7,
            name: CookieName::fromString('Chocolate Chip'),
            description: 'Crunchy',
            price: CookiePrice::fromString('2.99'),
            stock: 25,
            isActive: true,
            createdAt: '2024-01-01 00:00:00',
            updatedAt: '2024-01-02 00:00:00',
            deletedAt: null,
            version: 3
        );

        $view = CookieView::detail($cookie);

        $this->assertSame(7, $view->id);
        $this->assertSame('Chocolate Chip', $view->name);
        $this->assertSame('Crunchy', $view->description);
        $this->assertSame('2.99', $view->price);
        $this->assertSame(25, $view->stock);
        $this->assertTrue($view->isActive);
        $this->assertSame(3, $view->version);
        $this->assertSame('2024-01-01 00:00:00', $view->createdAt);
        $this->assertFalse($view->isDeleted);
        $this->assertTrue($view->isAvailable);
    }

    public function test_summary_view_omits_description_and_timestamps(): void
    {
        $cookie = Cookie::reconstitute(
            id: 9,
            name: CookieName::fromString('Oatmeal'),
            description: 'Heavy fibre',
            price: CookiePrice::fromString('3.50'),
            stock: 10,
            isActive: true,
            createdAt: '2024-01-01 00:00:00',
            updatedAt: '2024-01-02 00:00:00',
            deletedAt: null,
            version: 1
        );

        $view = CookieView::summary($cookie);

        $this->assertSame(9, $view->id);
        $this->assertSame('Oatmeal', $view->name);
        $this->assertNull($view->description);
        $this->assertNull($view->createdAt);
        $this->assertNull($view->updatedAt);
    }

    public function test_to_array_serialises_to_snake_case_keys(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Aaa'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: null,
            updatedAt: null,
            deletedAt: null,
            version: 0
        );

        $arr = CookieView::detail($cookie)->toArray();

        $this->assertArrayHasKey('is_active', $arr);
        $this->assertArrayHasKey('is_deleted', $arr);
        $this->assertArrayHasKey('is_available', $arr);
        $this->assertFalse($arr['is_active']);
    }

    public function test_summarise_maps_a_list(): void
    {
        $cookies = [
            Cookie::reconstitute(
                id: 1,
                name: CookieName::fromString('Aaa'),
                description: null,
                price: CookiePrice::fromString('1.00'),
                stock: 0,
                isActive: true,
                createdAt: null,
                updatedAt: null,
            deletedAt: null,
            version: 1
            ),
            Cookie::reconstitute(
                id: 2,
                name: CookieName::fromString('Bbb'),
                description: null,
                price: CookiePrice::fromString('2.00'),
                stock: 5,
                isActive: true,
                createdAt: null,
                updatedAt: null,
            deletedAt: null,
            version: 1
            ),
        ];

        $views = CookieView::summarise($cookies);

        $this->assertCount(2, $views);
        $this->assertSame(1, $views[0]->id);
        $this->assertSame(2, $views[1]->id);
        $this->assertContainsOnlyInstancesOf(CookieView::class, $views);
    }

    public function test_deleted_cookie_view_reflects_state(): void
    {
        $cookie = Cookie::reconstitute(
            id: 1,
            name: CookieName::fromString('Gone'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: null,
            updatedAt: null,
            deletedAt: '2024-05-01 00:00:00',
            version: 1
        );

        $view = CookieView::detail($cookie);

        $this->assertTrue($view->isDeleted);
        $this->assertFalse($view->isAvailable);
        $this->assertSame('2024-05-01 00:00:00', $view->deletedAt);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;

/**
 * Factory for creating test Cookie entities and data.
 *
 * Test Data Builder pattern for consistent test data creation.
 *
 * @package Tests\Support\Factories
 */
final class CookieFactory
{
    /**
     * Create a valid Cookie entity with default values.
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createCookie(array $overrides = []): Cookie
    {
        $defaults = [
            'name' => 'Chocolate Chip Cookie',
            'description' => 'Classic chocolate chip cookie',
            'price' => '2.99',
            'stock' => 100,
            'isActive' => true,
        ];

        $data = array_merge($defaults, $overrides);

        return Cookie::create(
            name: CookieName::fromString($data['name']),
            description: $data['description'],
            price: self::priceFromMixed($data['price']),
            stock: $data['stock'],
            isActive: $data['isActive']
        );
    }

    /**
     * Create a reconstituted Cookie (as if loaded from database).
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createPersistedCookie(array $overrides = []): Cookie
    {
        $defaults = [
            'id' => 1,
            'name' => 'Chocolate Chip Cookie',
            'description' => 'Classic chocolate chip cookie',
            'price' => '2.99',
            'stock' => 100,
            'isActive' => true,
            'createdAt' => '2025-10-21 10:00:00',
            'updatedAt' => '2025-10-21 10:00:00',
            'deletedAt' => null,
        ];

        $data = array_merge($defaults, $overrides);

        return Cookie::reconstitute(
            id: $data['id'],
            name: CookieName::fromString($data['name']),
            description: $data['description'],
            price: self::priceFromMixed($data['price']),
            stock: $data['stock'],
            isActive: $data['isActive'],
            createdAt: $data['createdAt'],
            updatedAt: $data['updatedAt'],
            deletedAt: $data['deletedAt']
        );
    }

    /**
     * Create database row data for a cookie.
     *
     * @param array<string, mixed> $overrides Override default values
     * @return array<string, mixed>
     */
    public static function createDatabaseRow(array $overrides = []): array
    {
        $defaults = [
            'id' => 1,
            'name' => 'Chocolate Chip Cookie',
            'description' => 'Classic chocolate chip cookie',
            'price' => '2.99',
            'stock' => 100,
            'is_active' => 1,
            'created_at' => '2025-10-21 10:00:00',
            'updated_at' => '2025-10-21 10:00:00',
            'deleted_at' => null,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create multiple cookies with unique names.
     *
     * @param int $count Number of cookies to create
     * @return array<int, Cookie>
     */
    public static function createMultiple(int $count): array
    {
        $cookies = [];

        for ($i = 1; $i <= $count; $i++) {
            $cookies[] = self::createCookie([
                'name' => sprintf('Test Cookie %d', $i),
                'price' => number_format(2.00 + ($i * 0.50), 2, '.', ''),
                'stock' => 50 + ($i * 10),
            ]);
        }

        return $cookies;
    }

    /**
     * Create form POST data for creating a cookie.
     *
     * @param array<string, mixed> $overrides Override default values
     * @return array<string, mixed>
     */
    public static function createFormData(array $overrides = []): array
    {
        $defaults = [
            'name' => 'Chocolate Chip Cookie',
            'description' => 'Classic chocolate chip cookie',
            'price' => '2.99',
            'stock' => '100',
            'is_active' => '1',
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create invalid form data for testing validation.
     *
     * @param string $invalidField Which field should be invalid
     * @return array<string, mixed>
     */
    public static function createInvalidFormData(string $invalidField): array
    {
        $data = self::createFormData();

        return match ($invalidField) {
            'name_empty' => array_merge($data, ['name' => '']),
            'name_too_short' => array_merge($data, ['name' => 'AB']),
            'name_too_long' => array_merge($data, ['name' => str_repeat('A', 101)]),
            'price_zero' => array_merge($data, ['price' => '0']),
            'price_negative' => array_merge($data, ['price' => '-1.99']),
            'stock_negative' => array_merge($data, ['stock' => '-10']),
            default => $data,
        };
    }

    private static function priceFromMixed(mixed $price): CookiePrice
    {
        if (is_string($price)) {
            return CookiePrice::fromString($price);
        }

        if (is_int($price) || is_float($price)) {
            return CookiePrice::fromString(number_format((float) $price, 2, '.', ''));
        }

        return CookiePrice::fromString('');
    }
}

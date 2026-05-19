<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeder for the cookies table.
 *
 * Creates 10 sample cookies with realistic data for testing and demonstration.
 *
 * Usage:
 * ```bash
 * php spark db:seed CookieSeeder
 * ```
 *
 * @package App\Database\Seeds
 */
class CookieSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'name' => 'Chocolate Chip',
                'description' => 'Classic chocolate chip cookies with premium dark chocolate chunks. Our most popular choice!',
                'price' => 2.99,
                'stock' => 85,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Oatmeal Raisin',
                'description' => 'Hearty oats with sweet raisins. A healthy and delicious option.',
                'price' => 2.49,
                'stock' => 62,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Peanut Butter',
                'description' => 'Rich and creamy peanut butter cookies. Contains peanuts.',
                'price' => 2.79,
                'stock' => 43,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Sugar Cookie',
                'description' => 'Simple and sweet traditional sugar cookies with a sprinkle of cinnamon.',
                'price' => 1.99,
                'stock' => 100,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Double Chocolate',
                'description' => 'For chocolate lovers! Double chocolate cookies with cocoa powder and chocolate chips.',
                'price' => 3.49,
                'stock' => 27,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Macadamia Nut',
                'description' => 'Premium cookies with whole macadamia nuts and white chocolate.',
                'price' => 4.99,
                'stock' => 18,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Gingerbread',
                'description' => 'Spiced gingerbread cookies with a hint of molasses. Seasonal favorite!',
                'price' => 2.99,
                'stock' => 0,
                'is_active' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Snickerdoodle',
                'description' => 'Cinnamon sugar cookies with a soft, chewy center.',
                'price' => 2.49,
                'stock' => 54,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Lemon Zest',
                'description' => 'Bright and refreshing lemon cookies with real lemon zest.',
                'price' => 2.79,
                'stock' => 36,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Almond Biscotti',
                'description' => 'Crunchy Italian-style biscotti perfect for dipping in coffee.',
                'price' => 3.29,
                'stock' => 41,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
        ];

        // Insert data
        $this->db->table('cookies')->insertBatch($data);
    }
}

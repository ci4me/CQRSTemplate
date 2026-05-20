<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Master seeder that runs all domain seeders.
 *
 * Usage:
 * ```bash
 * php spark db:seed DatabaseSeeder
 * ```
 *
 * @package App\Database\Seeds
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
        $this->call(CookieSeeder::class);
    }
}

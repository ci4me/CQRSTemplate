<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Seeder for the users table.
 *
 * Creates test accounts for development and manual testing.
 * Passwords are hashed with Argon2id (PHP default).
 *
 * Test accounts:
 * - admin@example.com / password123 (admin role)
 * - customer@example.com / password123 (customer role)
 *
 * Usage:
 * ```bash
 * php spark db:seed UserSeeder
 * ```
 *
 * @package App\Database\Seeds
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = password_hash('password123', PASSWORD_ARGON2ID);
        $now = date('Y-m-d H:i:s');

        $data = [
            [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password_hash' => $password,
                'role' => 'admin',
                'status' => 'active',
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'name' => 'Test Customer',
                'email' => 'customer@example.com',
                'password_hash' => $password,
                'role' => 'customer',
                'status' => 'active',
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ];

        $this->db->table('users')->insertBatch($data);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support\Factories;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;

/**
 * Factory for creating test User entities and data.
 *
 * Test Data Builder pattern for consistent test data creation.
 *
 * @package Tests\Support\Factories
 */
final class UserFactory
{
    /**
     * Create a valid User entity with default values.
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createUser(array $overrides = []): User
    {
        $defaults = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'SecureP@ssw0rd123!',
            'role' => UserRole::Customer,
        ];

        $data = array_merge($defaults, $overrides);

        return User::create(
            name: UserName::fromString($data['name']),
            email: Email::fromString($data['email']),
            hashedPassword: HashedPassword::fromPlaintext($data['password']),
            role: $data['role']
        );
    }

    /**
     * Create a reconstituted User (as if loaded from database).
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createPersistedUser(array $overrides = []): User
    {
        $defaults = [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$test$testhash',
            'role' => 'customer',
            'status' => 'active',
            'failedLoginAttempts' => 0,
            'lockedUntil' => null,
            'createdAt' => '2025-10-26 10:00:00',
            'updatedAt' => '2025-10-26 10:00:00',
            'deletedAt' => null,
        ];

        $data = array_merge($defaults, $overrides);

        return User::reconstitute(
            id: $data['id'],
            name: UserName::fromString($data['name']),
            email: Email::fromString($data['email']),
            hashedPassword: HashedPassword::fromHash($data['password_hash']),
            role: UserRole::from($data['role']),
            status: UserStatus::from($data['status']),
            failedLoginAttempts: $data['failedLoginAttempts'],
            lockedUntil: $data['lockedUntil'] ? new \DateTimeImmutable($data['lockedUntil']) : null,
            createdAt: new \DateTimeImmutable($data['createdAt']),
            updatedAt: $data['updatedAt'] ? new \DateTimeImmutable($data['updatedAt']) : null,
            deletedAt: $data['deletedAt'] ? new \DateTimeImmutable($data['deletedAt']) : null
        );
    }

    /**
     * Create database row data for a user.
     *
     * @param array<string, mixed> $overrides Override default values
     * @return array<string, mixed>
     */
    public static function createDatabaseRow(array $overrides = []): array
    {
        $defaults = [
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$test$testhash',
            'role' => 'customer',
            'status' => 'active',
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'created_at' => '2025-10-26 10:00:00',
            'updated_at' => '2025-10-26 10:00:00',
            'deleted_at' => null,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create multiple users with unique emails.
     *
     * @param int $count Number of users to create
     * @return array<int, User>
     */
    public static function createMultiple(int $count): array
    {
        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $users[] = self::createUser([
                'name' => sprintf('Test User %d', $i),
                'email' => sprintf('user%d@example.com', $i),
            ]);
        }

        return $users;
    }

    /**
     * Create form POST data for creating a user.
     *
     * @param array<string, mixed> $overrides Override default values
     * @return array<string, mixed>
     */
    public static function createFormData(array $overrides = []): array
    {
        $defaults = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'SecureP@ssw0rd123!',
            'password_confirm' => 'SecureP@ssw0rd123!',
            'role' => 'customer',
            'status' => 'active',
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
            'name_too_short' => array_merge($data, ['name' => 'A']),
            'name_too_long' => array_merge($data, ['name' => str_repeat('A', 101)]),
            'email_invalid' => array_merge($data, ['email' => 'invalid-email']),
            'email_empty' => array_merge($data, ['email' => '']),
            'password_weak' => array_merge($data, ['password' => 'weak', 'password_confirm' => 'weak']),
            'password_too_short' => array_merge($data, ['password' => 'Short1!', 'password_confirm' => 'Short1!']),
            'password_mismatch' => array_merge($data, ['password_confirm' => 'DifferentP@ss123!']),
            'role_invalid' => array_merge($data, ['role' => 'superadmin']),
            'status_invalid' => array_merge($data, ['status' => 'banned']),
            default => $data,
        };
    }

    /**
     * Create an admin user.
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createAdmin(array $overrides = []): User
    {
        return self::createUser(array_merge([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => UserRole::Admin,
        ], $overrides));
    }

    /**
     * Create a persisted admin user.
     *
     * @param array<string, mixed> $overrides Override default values
     */
    public static function createPersistedAdmin(array $overrides = []): User
    {
        return self::createPersistedUser(array_merge([
            'id' => 999,
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ], $overrides));
    }
}

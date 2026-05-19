<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Entities;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use CodeIgniter\Test\CIUnitTestCase;

final class UserTest extends CIUnitTestCase
{
    public function testCreateFactoryMethodCreatesNewUser(): void
    {
        $name = UserName::fromString('Test User');
        $email = Email::fromString('test@example.com');
        $password = HashedPassword::fromPlaintext('SecurePass123!');
        $role = UserRole::Customer;

        $user = User::create($name, $email, $password, $role);

        $this->assertNull($user->getId());
        $this->assertSame('Test User', $user->getName()->getValue());
        $this->assertTrue($user->getEmail()->equals($email));
        $this->assertSame($role, $user->getRole());
        $this->assertSame(UserStatus::Active, $user->getStatus());
        $this->assertSame(0, $user->getFailedLoginAttempts());
    }

    public function testReconstituteFactoryMethodCreatesUserFromDatabase(): void
    {
        $name = UserName::fromString('Test User');
        $email = Email::fromString('test@example.com');
        $password = HashedPassword::fromHash(password_hash('test', PASSWORD_ARGON2ID));
        $createdAt = new \DateTimeImmutable('2025-01-01 10:00:00');

        $user = User::reconstitute(
            id: 1,
            name: $name,
            email: $email,
            hashedPassword: $password,
            role: UserRole::Admin,
            status: UserStatus::Active,
            failedLoginAttempts: 0,
            lockedUntil: null,
            createdAt: $createdAt,
            updatedAt: null,
            deletedAt: null
        );

        $this->assertSame(1, $user->getId());
        $this->assertSame('Test User', $user->getName()->getValue());
        $this->assertTrue($user->getEmail()->equals($email));
        $this->assertSame(UserRole::Admin, $user->getRole());
    }

    public function testIsActiveReturnsTrueForActiveUser(): void
    {
        $user = $this->createTestUser();
        $this->assertTrue($user->isActive());
    }

    public function testIsActiveReturnsFalseForInactiveUser(): void
    {
        $user = $this->createTestUser();
        $user->deactivate();

        $this->assertFalse($user->isActive());
    }

    public function testActivateSetsStatusToActive(): void
    {
        $user = $this->createTestUser();
        $user->deactivate();
        $user->activate();

        $this->assertSame(UserStatus::Active, $user->getStatus());
    }

    public function testDeactivateSetsStatusToInactive(): void
    {
        $user = $this->createTestUser();
        $user->deactivate();

        $this->assertSame(UserStatus::Inactive, $user->getStatus());
    }

    public function testIncrementFailedLoginAttemptsIncrementsCounter(): void
    {
        $user = $this->createTestUser();

        $this->assertSame(0, $user->getFailedLoginAttempts());

        $user->incrementFailedLoginAttempts();
        $this->assertSame(1, $user->getFailedLoginAttempts());

        $user->incrementFailedLoginAttempts();
        $this->assertSame(2, $user->getFailedLoginAttempts());
    }

    public function testAccountLocksAfter5FailedAttempts(): void
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < 5; $i++) {
            $user->incrementFailedLoginAttempts();
        }

        $this->assertSame(5, $user->getFailedLoginAttempts());
        $this->assertNotNull($user->getLockedUntil());
        $this->assertTrue($user->isLockedOut());
    }

    public function testResetFailedLoginAttemptsClearsCounterAndLock(): void
    {
        $user = $this->createTestUser();

        for ($i = 0; $i < 5; $i++) {
            $user->incrementFailedLoginAttempts();
        }

        $this->assertTrue($user->isLockedOut());

        $user->resetFailedLoginAttempts();

        $this->assertSame(0, $user->getFailedLoginAttempts());
        $this->assertNull($user->getLockedUntil());
        $this->assertFalse($user->isLockedOut());
    }

    public function testIsLockedOutReturnsFalseForNonLockedUser(): void
    {
        $user = $this->createTestUser();
        $this->assertFalse($user->isLockedOut());
    }

    public function testChangePasswordUpdatesPassword(): void
    {
        $user = $this->createTestUser();
        $newPassword = HashedPassword::fromPlaintext('NewSecurePass456!');

        $user->changePassword($newPassword);

        $this->assertSame($newPassword, $user->getHashedPassword());
    }

    private function createTestUser(): User
    {
        return User::create(
            name: UserName::fromString('Test User'),
            email: Email::fromString('test@example.com'),
            hashedPassword: HashedPassword::fromPlaintext('SecurePass123!'),
            role: UserRole::Customer
        );
    }
}

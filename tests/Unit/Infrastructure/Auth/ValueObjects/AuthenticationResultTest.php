<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\ValueObjects;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Auth\ValueObjects\AuthenticationResult;
use Tests\Support\UnitTestCase;

final class AuthenticationResultTest extends UnitTestCase
{
    public function test_create_exposes_all_accessors(): void
    {
        $user = $this->makeUser();
        $expiresAt = time() + 3600;

        $result = AuthenticationResult::create($user, 'access-jwt', 'refresh-jwt', $expiresAt);

        $this->assertSame($user, $result->getUser());
        $this->assertSame('access-jwt', $result->getAccessToken());
        $this->assertSame('refresh-jwt', $result->getRefreshToken());
        $this->assertSame($expiresAt, $result->getExpiresAt());
    }

    public function test_to_array_returns_full_payload(): void
    {
        $user = $this->makeUser();
        $expiresAt = time() + 1800;

        $result = AuthenticationResult::create($user, 'A', 'R', $expiresAt);
        $payload = $result->toArray();

        $this->assertSame('A', $payload['access_token']);
        $this->assertSame('R', $payload['refresh_token']);
        $this->assertSame($expiresAt, $payload['expires_at']);
        $this->assertGreaterThan(0, $payload['expires_in']);
        $this->assertSame('jane@example.com', $payload['user']['email']);
        $this->assertSame('Jane Doe', $payload['user']['name']);
        $this->assertSame('admin', $payload['user']['role']);
    }

    public function test_expires_in_clamps_to_zero_when_already_expired(): void
    {
        $result = AuthenticationResult::create($this->makeUser(), 'A', 'R', 1);

        $payload = $result->toArray();

        $this->assertSame(0, $payload['expires_in']);
    }

    private function makeUser(): User
    {
        return User::reconstitute(
            id: 42,
            name: UserName::fromString('Jane Doe'),
            email: Email::fromString('jane@example.com'),
            hashedPassword: HashedPassword::fromHash(password_hash('S3curePass!', PASSWORD_BCRYPT)),
            role: UserRole::Admin,
            status: UserStatus::Active,
            failedLoginAttempts: 0,
            lockedUntil: null,
            createdAt: new \DateTimeImmutable('2024-01-01'),
            updatedAt: new \DateTimeImmutable('2024-01-01'),
            deletedAt: null,
        );
    }
}

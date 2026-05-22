<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\ValueObjects\PasswordResetToken;
use Tests\Support\FeatureTestCase;

/**
 * E2E coverage for the password reset surface.
 *
 * The "request reset" endpoint always answers 200 (anti-enumeration)
 * but only writes a `password_reset_tokens` row when the email maps
 * to a real account. The "confirm reset" endpoint exchanges a valid
 * token for a new password hash.
 */
final class PasswordResetFlowTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';
    private const string NEW_PASSWORD = 'BrandNewPass456$%^';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_request_reset_stores_token_row_for_known_email(): void
    {
        $email = 'reset-known@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/request-reset', [
            'email' => $email,
        ]);

        $response->assertStatus(200);
        $tokens = \Config\Database::connect()
            ->table('password_reset_tokens')
            ->countAllResults();
        $this->assertSame(1, $tokens);
    }

    public function test_request_reset_returns_200_even_for_unknown_email(): void
    {
        // Anti-enumeration: the endpoint must not reveal whether the
        // account exists, so it answers 200 regardless.
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/request-reset', [
            'email' => 'ghost-reset@test.local',
        ]);

        $response->assertStatus(200);
        $tokens = \Config\Database::connect()
            ->table('password_reset_tokens')
            ->countAllResults();
        $this->assertSame(0, $tokens);
    }

    public function test_request_reset_returns_400_when_email_missing(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/request-reset', []);

        $response->assertStatus(400);
    }

    public function test_confirm_reset_updates_password_hash_with_valid_token(): void
    {
        $email = 'reset-confirm@test.local';
        $user = $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->insertResetToken((int) $user->getId());

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'token' => $token,
            'new_password' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(200);

        // Login with the new password proves the hash was rotated.
        $login = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => self::NEW_PASSWORD,
        ]);
        $login->assertStatus(200);
    }

    public function test_confirm_reset_returns_400_for_unknown_token(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'token' => 'totally-fake-token-value',
            'new_password' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(400);
    }

    public function test_confirm_reset_returns_400_when_payload_incomplete(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'new_password' => self::NEW_PASSWORD,
        ]);

        $response->assertStatus(400);
    }

    public function test_confirm_reset_deletes_the_token_after_use(): void
    {
        $email = 'reset-cleanup@test.local';
        $user = $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->insertResetToken((int) $user->getId());

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'token' => $token,
            'new_password' => self::NEW_PASSWORD,
        ]);
        $response->assertStatus(200);

        $remaining = \Config\Database::connect()
            ->table('password_reset_tokens')
            ->countAllResults();
        $this->assertSame(0, $remaining);
    }

    private function createUser(string $email, string $password, UserRole $role = UserRole::Customer): User
    {
        $user = User::create(
            name: UserName::fromString('Test User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext($password),
            role: $role,
        );

        $repository = $this->getUserRepository();
        $userId = $repository->save($user);

        $found = $repository->findById($userId);
        if ($found === null) {
            throw new \RuntimeException('Failed to persist user during test setup');
        }

        return $found;
    }

    /**
     * Insert a password reset token directly so the test does not
     * depend on the request-reset email side-effect.
     */
    private function insertResetToken(int $userId): string
    {
        $resetToken = PasswordResetToken::generate();
        $expiresAt = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

        \Config\Database::connect()->table('password_reset_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $resetToken->getTokenHash(),
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $resetToken->getToken();
    }

    private function getUserRepository(): UserRepository
    {
        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);

        return $repo;
    }
}

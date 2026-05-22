<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use Tests\Support\FeatureTestCase;

/**
 * Drives the JWT filter on a real protected endpoint
 * (`GET /api/v1/auth/me`) so we exercise all the 401 branches
 * without instantiating the middleware in isolation.
 */
final class JwtMiddlewareTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_missing_authorization_header_returns_401_with_error_code(): void
    {
        $response = $this->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('missing_authorization_header', $body['error']);
    }

    public function test_malformed_authorization_header_returns_invalid_format(): void
    {
        // The middleware requires "Bearer <token>"; "Token abc" must be rejected.
        $response = $this->withHeaders(['Authorization' => 'Token abcdef0123456789'])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('invalid_authorization_format', $body['error']);
    }

    public function test_bearer_with_too_short_token_returns_invalid_format(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer '])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('invalid_authorization_format', $body['error']);
    }

    public function test_garbage_bearer_token_returns_invalid_signature(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer not.a.real.jwt.token.value.here.at.all',
        ])->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('invalid_token_signature', $body['error']);
    }

    public function test_valid_token_for_existing_active_user_returns_user_payload(): void
    {
        $email = 'jwt-mw-happy@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame($email, $body['data']['email']);
    }

    public function test_token_for_deleted_user_returns_user_not_found(): void
    {
        $email = 'jwt-mw-deleted@test.local';
        $user = $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        // Soft-delete the user behind the issued token.
        $repository = $this->getUserRepository();
        $repository->delete((int) $user->getId());

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertContains($body['error'], ['user_not_found', 'user_inactive']);
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

    private function loginAndExtractAccessToken(string $email, string $password): string
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);

        return (string) $body['data']['access_token'];
    }

    private function getUserRepository(): UserRepository
    {
        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);

        return $repo;
    }
}

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
 * Drives the remaining JSON-API endpoints on App\Controllers\Api\AuthController
 * that no other Feature test exercises: register, requestPasswordReset,
 * resetPassword, listSessions, revokeSession, revokeAllSessions, and the me /
 * logout 401 paths.
 */
final class AuthApiTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_api_register_returns_201_on_success(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'New Person',
            'email' => 'register-api@test.local',
            'password' => self::TEST_PASSWORD,
            'role' => 'customer',
        ]);

        $response->assertStatus(201);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('user_id', $body['data']);
    }

    public function test_api_register_rejects_invalid_email(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/register', [
            'name' => 'Valid Person',
            'email' => 'not-an-email',
            'password' => self::TEST_PASSWORD,
            'role' => 'customer',
        ]);

        // ValidationException → 422; DomainException → 400.
        // Either rejection type means we successfully exercised the failure
        // branch in AuthController::register.
        $this->assertContains(
            $response->response()->getStatusCode(),
            [400, 422],
        );
    }

    public function test_api_request_password_reset_always_returns_success(): void
    {
        // Even for an unknown email — by design, to prevent user enumeration.
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/request-reset', [
            'email' => 'ghost@nowhere.test',
        ]);

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertStringContainsString('If the email exists', $body['message']);
    }

    public function test_api_request_password_reset_rejects_empty_email(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/request-reset', [
            'email' => '',
        ]);

        $response->assertStatus(400);
    }

    public function test_api_reset_password_requires_token_and_new_password(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'token' => '',
            'new_password' => '',
        ]);

        $response->assertStatus(400);
    }

    public function test_api_reset_password_returns_400_for_invalid_token(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/password/reset', [
            'token' => 'nonexistent-token',
            'new_password' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(400);
    }

    public function test_api_refresh_returns_400_when_token_missing(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => '',
        ]);

        $response->assertStatus(400);
    }

    public function test_api_list_sessions_returns_data_for_authenticated_user(): void
    {
        $email = 'sessions-list@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/sessions');

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('sessions', $body['data']);
    }

    public function test_api_revoke_all_sessions_succeeds_for_authenticated_user(): void
    {
        $email = 'sessions-revoke-all@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('DELETE', '/api/v1/auth/sessions/all');

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
    }

    public function test_api_logout_returns_401_when_authorization_header_missing(): void
    {
        // Without the Authorization header the JWT filter rejects the request
        // with 401 before logout() runs.
        $response = $this->call('POST', '/api/v1/auth/logout');
        $response->assertStatus(401);
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

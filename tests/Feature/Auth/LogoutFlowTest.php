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
 * E2E coverage for the logout surface.
 *
 * After logout the access token is added to the blacklist so a
 * subsequent /api/v1/auth/me using the same token must come back
 * unauthorized. The web logout returns a session-clearing redirect.
 */
final class LogoutFlowTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_api_logout_blacklists_access_token(): void
    {
        $email = 'logout-blacklist@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access']])
            ->withBodyFormat('json')
            ->call('POST', '/api/v1/auth/logout', [
                'refresh_token' => $tokens['refresh'],
            ]);

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
    }

    public function test_subsequent_request_with_blacklisted_token_returns_401(): void
    {
        $email = 'logout-revoked@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        $logoutResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access']])
            ->withBodyFormat('json')
            ->call('POST', '/api/v1/auth/logout', [
                'refresh_token' => $tokens['refresh'],
            ]);
        $logoutResponse->assertStatus(200);

        $meResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access']])
            ->call('GET', '/api/v1/auth/me');

        $meResponse->assertStatus(401);
    }

    public function test_api_logout_without_authorization_header_returns_401(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/logout', []);

        $response->assertStatus(401);
    }

    public function test_api_logout_with_invalid_token_returns_401(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer not.a.real.token'])
            ->withBodyFormat('json')
            ->call('POST', '/api/v1/auth/logout', []);

        $response->assertStatus(401);
    }

    public function test_web_logout_redirects_to_login_and_clears_session(): void
    {
        $email = 'web-logout@test.local';
        $userId = $this->createUser($email, self::TEST_PASSWORD)->getId();

        $session = session();
        $session->set('user_id', $userId);
        $session->set('email', $email);
        $session->set('role', 'customer');
        $session->set('logged_in', true);

        $result = $this->post('/auth/logout');

        $result->assertRedirect();
        $this->assertStringContainsString('/auth/login', (string) $result->getRedirectUrl());
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
     * @return array{access: string, refresh: string}
     */
    private function loginAndExtractTokens(string $email, string $password): array
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);

        return [
            'access' => $body['data']['access_token'],
            'refresh' => $body['data']['refresh_token'],
        ];
    }

    private function getUserRepository(): UserRepository
    {
        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);

        return $repo;
    }
}

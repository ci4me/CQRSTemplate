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
 * E2E coverage for the login surface (web form + JSON API).
 *
 * The companion handler is exercised in unit tests; here we drive
 * the full HTTP stack so the controller wiring, command bus, and
 * repository layer get touched together.
 */
final class LoginFlowTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        // Inject MockSession so the controller's session->regenerate()
        // becomes a no-op instead of triggering a php session warning.
        $this->mockSession();
    }

    public function test_api_login_returns_jwt_tokens_for_valid_credentials(): void
    {
        $email = 'api-login@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(200);
        $payload = $response->getJSON();
        $this->assertIsString($payload);
        $body = json_decode($payload, true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertSame('Bearer', $body['data']['token_type']);
        $this->assertSame($email, $body['data']['user']['email']);
    }

    public function test_api_login_returns_401_for_wrong_password(): void
    {
        $email = 'api-wrong@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => $email,
            'password' => 'TotallyDifferent123!@#',
        ]);

        $response->assertStatus(401);
    }

    public function test_api_login_returns_401_for_unknown_email(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/login', [
            'email' => 'ghost@test.local',
            'password' => self::TEST_PASSWORD,
        ]);

        $response->assertStatus(401);
    }

    public function test_web_login_redirects_to_dashboard_on_success(): void
    {
        $email = 'web-login@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $result = $this->post('/auth/login', [
            'email' => $email,
            'password' => self::TEST_PASSWORD,
        ]);

        $result->assertRedirect();
        $location = (string) $result->getRedirectUrl();
        $this->assertStringContainsString('/dashboard', $location);
    }

    public function test_web_login_redirects_back_with_error_on_invalid_credentials(): void
    {
        $email = 'web-bad@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $result = $this->post('/auth/login', [
            'email' => $email,
            'password' => 'WrongPassword99!@#',
        ]);

        $result->assertRedirect();
        $this->assertFlashMessage('error');
    }

    public function test_api_me_endpoint_requires_authorization_header(): void
    {
        $response = $this->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_api_me_endpoint_returns_user_for_valid_token(): void
    {
        $email = 'me-endpoint@test.local';
        $this->createUser($email, self::TEST_PASSWORD);

        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame($email, $body['data']['email']);
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
        $this->assertArrayHasKey('access_token', $body['data']);

        return $body['data']['access_token'];
    }

    private function getUserRepository(): UserRepository
    {
        $repo = \Config\Services::repository('userRepository');
        assert($repo instanceof UserRepository);

        return $repo;
    }
}

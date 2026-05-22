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
 * E2E coverage for the refresh-token rotation surface.
 *
 * The API exchanges a valid refresh token for a fresh access+refresh
 * pair. Revoked or malformed refresh tokens must result in 401.
 */
final class RefreshTokenFlowTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    private const string TEST_PASSWORD = 'ValidPass123!@#';

    protected function setUp(): void
    {
        parent::setUp();
        \CodeIgniter\Config\Services::cache()->clean();
        $this->mockSession();
    }

    public function test_refresh_returns_new_token_pair_for_valid_refresh_token(): void
    {
        $email = 'refresh-ok@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        // Sleep 1s so the rotated access token has a different iat/exp
        sleep(1);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh'],
        ]);

        $response->assertStatus(200);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('access_token', $body['data']);
        $this->assertArrayHasKey('refresh_token', $body['data']);
        $this->assertNotSame($tokens['access'], $body['data']['access_token']);
        $this->assertNotSame($tokens['refresh'], $body['data']['refresh_token']);
    }

    public function test_refresh_returns_400_when_refresh_token_missing(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', []);

        // Bad request — controller answers 400 when payload is incomplete.
        $response->assertStatus(400);
    }

    public function test_refresh_returns_401_for_malformed_token(): void
    {
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => 'not.a.valid.jwt',
        ]);

        $response->assertStatus(401);
    }

    public function test_refresh_returns_401_when_token_is_blacklisted(): void
    {
        $email = 'refresh-blacklist@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        // Explicit logout blacklists both access + refresh.
        $logout = $this->withHeaders(['Authorization' => 'Bearer ' . $tokens['access']])
            ->withBodyFormat('json')
            ->call('POST', '/api/v1/auth/logout', [
                'refresh_token' => $tokens['refresh'],
            ]);
        $logout->assertStatus(200);

        // Attempting to refresh with the now-blacklisted token must fail.
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh'],
        ]);
        $response->assertStatus(401);
    }

    public function test_refresh_returns_401_when_token_jti_is_marked_revoked(): void
    {
        $email = 'refresh-jti-revoked@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        // Manually mark the refresh token's jti as revoked in the table.
        $jwtService = \Config\Services::jwtService();
        $payload = $jwtService->getTokenPayload($tokens['refresh']);
        $jti = $payload['jti'] ?? null;
        $this->assertIsString($jti);

        $db = \Config\Database::connect();
        $db->table('refresh_tokens')->insert([
            'user_id' => $payload['user_id'],
            'jti' => $jti,
            'expires_at' => date('Y-m-d H:i:s', $payload['exp']),
            'revoked' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $tokens['refresh'],
        ]);
        $response->assertStatus(401);
    }

    public function test_refresh_rejects_access_token_used_as_refresh(): void
    {
        $email = 'refresh-wrong-type@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $tokens = $this->loginAndExtractTokens($email, self::TEST_PASSWORD);

        // Passing the access token where a refresh token is expected
        // must fail the token-type guard inside the handler.
        $response = $this->withBodyFormat('json')->call('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $tokens['access'],
        ]);

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

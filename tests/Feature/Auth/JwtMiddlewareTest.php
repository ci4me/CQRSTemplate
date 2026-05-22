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

    public function test_token_for_blacklisted_session_returns_blacklisted_error(): void
    {
        $email = 'jwt-mw-blacklisted@test.local';
        $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        // Logout invalidates the token via the blacklist service.
        $logout = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->withBodyFormat('json')
            ->call('POST', '/api/v1/auth/logout', []);
        $logout->assertStatus(200);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('token_blacklisted', $body['error']);
    }

    public function test_token_after_session_idle_timeout_is_revoked(): void
    {
        // Force a very short idle window so the next request lands past it.
        putenv('AUTH_IDLE_TIMEOUT_SECONDS=1');

        try {
            $email = 'jwt-mw-idle@test.local';
            $this->createUser($email, self::TEST_PASSWORD);
            $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

            // Force the session row's last_activity_at into the past.
            \Config\Database::connect()
                ->table('sessions')
                ->update(['last_activity_at' => '2000-01-01 00:00:00']);

            $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->call('GET', '/api/v1/auth/me');

            $response->assertStatus(401);
            $body = json_decode((string) $response->getJSON(), true);
            $this->assertIsArray($body);
            $this->assertSame('idle_timeout_exceeded', $body['error']);
        } finally {
            putenv('AUTH_IDLE_TIMEOUT_SECONDS');
        }
    }

    public function test_token_with_fingerprint_mismatch_within_grace_period_is_allowed(): void
    {
        // 1-hour grace period — the fingerprint mismatch is tolerated because
        // the session was created moments ago. Exercises the early-return
        // branch in validateDeviceFingerprint() (~line 270).
        putenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD=3600');

        try {
            $email = 'jwt-mw-grace@test.local';
            $this->createUser($email, self::TEST_PASSWORD);
            $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

            \Config\Database::connect()
                ->table('sessions')
                ->update(['device_fingerprint' => 'other-fingerprint-but-grace-active']);

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'GraceBrowser/1.0',
            ])->call('GET', '/api/v1/auth/me');

            $response->assertStatus(200);
        } finally {
            putenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD');
        }
    }

    public function test_token_for_explicitly_suspended_user_returns_user_inactive(): void
    {
        $email = 'jwt-mw-suspended@test.local';
        $user = $this->createUser($email, self::TEST_PASSWORD);
        $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

        // Flip the row to `status = suspended` directly so findById() returns
        // the user (no soft-delete) but isActive() returns false. That is
        // the exact code path that returns 'user_inactive' (lines 181-189).
        \Config\Database::connect()
            ->table('users')
            ->where('id', (int) $user->getId())
            ->update(['status' => 'suspended']);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->call('GET', '/api/v1/auth/me');

        $response->assertStatus(401);
        $body = json_decode((string) $response->getJSON(), true);
        $this->assertIsArray($body);
        $this->assertSame('user_inactive', $body['error']);
    }

    public function test_token_with_device_fingerprint_mismatch_is_rejected(): void
    {
        // Disable the grace period so any UA change is rejected immediately.
        putenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD=0');

        try {
            $email = 'jwt-mw-fp@test.local';
            $this->createUser($email, self::TEST_PASSWORD);
            $token = $this->loginAndExtractAccessToken($email, self::TEST_PASSWORD);

            // Forcibly overwrite the device_fingerprint so the next request's
            // UA hash will not match.
            \Config\Database::connect()
                ->table('sessions')
                ->update(['device_fingerprint' => 'definitely-not-the-real-fingerprint']);

            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'User-Agent' => 'TotallyDifferentBrowser/9.99',
            ])->call('GET', '/api/v1/auth/me');

            $response->assertStatus(401);
            $body = json_decode((string) $response->getJSON(), true);
            $this->assertIsArray($body);
            $this->assertSame('device_fingerprint_mismatch', $body['error']);
        } finally {
            putenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD');
        }
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

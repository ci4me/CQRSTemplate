<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Auth\Services;

use App\Domain\User\Entities\User;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Auth\Services\JwtService;
use Tests\Support\UnitTestCase;

/**
 * Pins JwtService's authn-critical surface area. The constructor reads its
 * secrets from getenv() at construction time, so these tests mutate env vars
 * via putenv() and restore them in tearDown to keep parallel suites safe.
 *
 * What's covered:
 *  - construction fails if JWT_SECRET_KEY is missing
 *  - construction fails if the secret is too short / a known weak value
 *  - access vs refresh tokens carry the expected payload claims
 *  - validateToken() rejects tampered signatures
 *  - validateToken() enforces the expected_type guard (access vs refresh)
 *  - validateToken() falls back to JWT_SECRET_KEY_OLD during rotation
 *  - getTokenPayload() decodes WITHOUT verifying signature (intentional helper)
 */
final class JwtServiceTest extends UnitTestCase
{
    private const string STRONG_SECRET = 'a2f1c4d8e9b3a7f6c2d8e1f4a9b6c3d8e5f2a1b4c7d6e9f8a3b2c1d4e5f6a7b8';
    private const string STRONG_SECRET_OLD = 'b1c4e6d9f2a8c5e7d4f1a6b9c2d5e8f3a4b7c0d3e6f9a2b5c8d1e4f7a0b3c6d9';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot the env vars we mutate; restored in tearDown so we don't
        // leak state across tests or into the rest of the suite.
        foreach (['JWT_SECRET_KEY', 'JWT_SECRET_KEY_OLD', 'AUTH_TOKEN_TTL', 'AUTH_REFRESH_TOKEN_TTL'] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key); // unset
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $key => $previous) {
            if ($previous === false) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $previous);
        }
        parent::tearDown();
    }

    public function test_construction_fails_without_secret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET_KEY environment variable is not set');

        new JwtService();
    }

    public function test_construction_fails_on_short_secret(): void
    {
        putenv('JWT_SECRET_KEY=too-short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Weak or default JWT secret');

        new JwtService();
    }

    public function test_construction_fails_on_known_weak_secret(): void
    {
        putenv('JWT_SECRET_KEY=your-secret-key-change-in-production-please-rotate-this');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Weak or default JWT secret');

        new JwtService();
    }

    public function test_access_token_carries_user_role_and_type(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();

        $token = $service->generateAccessToken($this->user(42, UserRole::Admin));
        $payload = $service->validateToken($token, 'access');

        $this->assertSame(42, $payload['user_id']);
        $this->assertSame('admin', $payload['role']);
        $this->assertSame('access', $payload['type']);
        $this->assertSame('cqrs-auth', $payload['iss']);
        $this->assertNotEmpty($payload['jti']);
    }

    public function test_refresh_token_does_not_carry_role_but_carries_type(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();

        $token = $service->generateRefreshToken($this->user(7, UserRole::Customer));
        $payload = $service->validateToken($token, 'refresh');

        $this->assertSame(7, $payload['user_id']);
        $this->assertSame('refresh', $payload['type']);
        $this->assertArrayNotHasKey('role', $payload, 'refresh tokens should not carry role claim');
    }

    public function test_validate_rejects_access_token_presented_as_refresh(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();
        $accessToken = $service->generateAccessToken($this->user(1));

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid token type');

        $service->validateToken($accessToken, 'refresh');
    }

    public function test_validate_rejects_tampered_signature(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();
        $token = $service->generateAccessToken($this->user(1));

        // Flip the very last byte of the signature segment. A header/payload
        // edit would just decode garbage; this is a sharper failure mode.
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        $this->expectException(\Exception::class);
        $service->validateToken($tampered);
    }

    public function test_old_secret_is_accepted_during_rotation_period(): void
    {
        // Phase 1: token issued with the "old" secret.
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET_OLD);
        $signer = new JwtService();
        $oldToken = $signer->generateAccessToken($this->user(99));

        // Phase 2: rotate — new secret active, old still configured for fallback.
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        putenv('JWT_SECRET_KEY_OLD=' . self::STRONG_SECRET_OLD);
        $validator = new JwtService();

        // The old token must still validate via the fallback path.
        $payload = $validator->validateToken($oldToken, 'access');
        $this->assertSame(99, $payload['user_id']);
    }

    public function test_token_signed_with_unknown_secret_is_rejected_even_with_rotation(): void
    {
        // Configure rotation slot — both current and old are valid.
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        putenv('JWT_SECRET_KEY_OLD=' . self::STRONG_SECRET_OLD);
        $service = new JwtService();

        // Now forge a token with a THIRD, unknown secret.
        $bogus = $this->encodeJwt(
            payload: ['user_id' => 1, 'type' => 'access', 'exp' => time() + 60],
            secret: 'c9e8d7c6b5a4938271605f4e3d2c1b0a9988776655443322110011223344556677',
        );

        $this->expectException(\Exception::class);
        $service->validateToken($bogus, 'access');
    }

    public function test_get_token_payload_decodes_without_verifying(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();

        // Forge a syntactically-valid token whose signature is irrelevant.
        // getTokenPayload() is documented as "no validation" — it must still
        // decode the payload segment regardless of who signed it.
        $token = $this->encodeJwt(
            payload: ['user_id' => 123, 'type' => 'access'],
            secret: self::STRONG_SECRET_OLD, // any strong key; signature is not checked here
        );

        $payload = $service->getTokenPayload($token);
        $this->assertSame(123, $payload['user_id']);
        $this->assertSame('access', $payload['type']);
    }

    public function test_get_token_payload_rejects_malformed_token(): void
    {
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);
        $service = new JwtService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JWT format');

        $service->getTokenPayload('not-a-real-jwt');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function user(int $id, UserRole $role = UserRole::Customer): User
    {
        return User::reconstitute(
            id: $id,
            name: UserName::fromString('Test User'),
            email: Email::fromString('test@example.com'),
            hashedPassword: HashedPassword::fromHash(password_hash('Some-Pwd-1!', PASSWORD_BCRYPT)),
            role: $role,
            status: UserStatus::Active,
            failedLoginAttempts: 0,
            lockedUntil: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: null,
            deletedAt: null,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodeJwt(array $payload, string $secret): string
    {
        return \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');
    }
}

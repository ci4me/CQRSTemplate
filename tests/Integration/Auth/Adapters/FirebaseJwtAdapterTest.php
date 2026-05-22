<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Adapters;

use App\Domain\User\Entities\User;
use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Domain\User\ValueObjects\UserStatus;
use App\Infrastructure\Auth\Adapters\Jwt\FirebaseJwtAdapter;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for FirebaseJwtAdapter — every branch in authenticate(),
 * validateToken(), and generateToken() including the early-return guards
 * for inactive/locked users, the blacklist check, the missing-claim guard,
 * and the catch-all on invalid tokens.
 */
#[AllowMockObjectsWithoutExpectations]
final class FirebaseJwtAdapterTest extends IntegrationTestCase
{
    private const string STRONG_SECRET = 'a2f1c4d8e9b3a7f6c2d8e1f4a9b6c3d8e5f2a1b4c7d6e9f8a3b2c1d4e5f6a7b8';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    private TokenBlacklistInterface $blacklist;
    private UserRepository $userRepository;
    private JwtService $jwtService;
    private FirebaseJwtAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['JWT_SECRET_KEY', 'JWT_SECRET_KEY_OLD', 'AUTH_TOKEN_TTL'] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);

        $this->jwtService = new JwtService();
        $this->blacklist = $this->createMock(TokenBlacklistInterface::class);
        $logger = LoggerFactory::create('test.auth.jwt-adapter');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));

        $this->adapter = new FirebaseJwtAdapter(
            $this->jwtService,
            $this->blacklist,
            $this->userRepository,
        );
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

    public function test_authenticate_returns_success_with_valid_credentials(): void
    {
        $user = $this->persistUser('valid@example.com', 'StrongP@ssw0rd!');

        $result = $this->adapter->authenticate($user, 'StrongP@ssw0rd!');

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->accessToken);
        $this->assertNotNull($result->refreshToken);
        $this->assertSame(3600, $result->expiresIn);
    }

    public function test_authenticate_rejects_wrong_password(): void
    {
        $user = $this->persistUser('wrong-pw@example.com', 'StrongP@ssw0rd!');

        $result = $this->adapter->authenticate($user, 'NotTheRightPwd!1');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid credentials', $result->errorMessage);
    }

    public function test_authenticate_rejects_inactive_user(): void
    {
        $user = $this->buildUser(1, 'inactive@example.com', UserStatus::Inactive);

        $result = $this->adapter->authenticate($user, 'StrongP@ssw0rd!');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Account is inactive', $result->errorMessage);
    }

    public function test_authenticate_rejects_locked_user(): void
    {
        $user = $this->buildUser(
            id: 2,
            email: 'locked@example.com',
            status: UserStatus::Active,
            lockedUntil: new \DateTimeImmutable('+10 minutes'),
        );

        $result = $this->adapter->authenticate($user, 'StrongP@ssw0rd!');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Account is locked', $result->errorMessage);
    }

    public function test_authenticate_respects_custom_token_ttl_from_env(): void
    {
        putenv('AUTH_TOKEN_TTL=120');

        $user = $this->persistUser('ttl@example.com', 'StrongP@ssw0rd!');
        $result = $this->adapter->authenticate($user, 'StrongP@ssw0rd!');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(120, $result->expiresIn);
    }

    public function test_validate_token_returns_null_for_blacklisted(): void
    {
        $user = $this->persistUser('bl@example.com', 'StrongP@ssw0rd!');
        $token = $this->jwtService->generateAccessToken($user);

        $this->blacklist->method('isBlacklisted')->with($token)->willReturn(true);

        $this->assertNull($this->adapter->validateToken($token));
    }

    public function test_validate_token_returns_user_for_valid_token(): void
    {
        $user = $this->persistUser('valid-tok@example.com', 'StrongP@ssw0rd!');
        $token = $this->jwtService->generateAccessToken($user);
        $this->blacklist->method('isBlacklisted')->willReturn(false);

        $resolved = $this->adapter->validateToken($token);

        $this->assertNotNull($resolved);
        $this->assertSame($user->getId(), $resolved->getId());
    }

    public function test_validate_token_returns_null_for_garbage(): void
    {
        $this->blacklist->method('isBlacklisted')->willReturn(false);

        $this->assertNull($this->adapter->validateToken('this.is.not.a.real.jwt'));
    }

    public function test_validate_token_returns_null_when_claim_missing(): void
    {
        $this->blacklist->method('isBlacklisted')->willReturn(false);

        // Sign a token WITHOUT a user_id claim so the missing-claim guard fires.
        $tokenString = \Firebase\JWT\JWT::encode(
            payload: [
                'iss' => 'cqrs-auth',
                'aud' => 'cqrs-app',
                'iat' => time(),
                'exp' => time() + 300,
                'jti' => bin2hex(random_bytes(8)),
                'type' => 'access',
            ],
            key: self::STRONG_SECRET,
            alg: 'HS256',
        );

        $this->assertNull($this->adapter->validateToken($tokenString));
    }

    public function test_generate_token_returns_access_token_with_default_ttl(): void
    {
        $user = $this->persistUser('gen@example.com', 'StrongP@ssw0rd!');

        $token = $this->adapter->generateToken($user);

        $this->assertNotEmpty($token->getValue());
        $this->assertFalse($token->isExpired());
    }

    public function test_generate_token_respects_custom_ttl(): void
    {
        putenv('AUTH_TOKEN_TTL=60');
        $user = $this->persistUser('gen-ttl@example.com', 'StrongP@ssw0rd!');

        $token = $this->adapter->generateToken($user);
        $diff = $token->getExpiresAt()->getTimestamp() - time();

        $this->assertGreaterThanOrEqual(55, $diff);
        $this->assertLessThanOrEqual(65, $diff);
    }

    private function persistUser(string $email, string $password): User
    {
        $user = User::create(
            name: UserName::fromString('JWT User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext($password),
            role: UserRole::Customer,
        );

        $id = $this->userRepository->save($user);
        $reloaded = $this->userRepository->findById($id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }

    private function buildUser(
        int $id,
        string $email,
        UserStatus $status,
        ?\DateTimeImmutable $lockedUntil = null,
    ): User {
        return User::reconstitute(
            id: $id,
            name: UserName::fromString('Test User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext('StrongP@ssw0rd!'),
            role: UserRole::Customer,
            status: $status,
            failedLoginAttempts: 0,
            lockedUntil: $lockedUntil,
            createdAt: new \DateTimeImmutable('-1 hour'),
            updatedAt: null,
            deletedAt: null,
        );
    }
}

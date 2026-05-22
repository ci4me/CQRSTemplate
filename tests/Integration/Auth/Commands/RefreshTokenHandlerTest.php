<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Commands;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\Commands\RefreshToken\RefreshTokenCommand;
use App\Infrastructure\Auth\Commands\RefreshToken\RefreshTokenHandler;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests targeting RefreshTokenHandler edge branches not hit by
 * Phase A's happy-path feature tests — specifically the "valid refresh
 * token whose user_id no longer exists" path (lines 98-103).
 */
final class RefreshTokenHandlerTest extends IntegrationTestCase
{
    private const string STRONG_SECRET = 'a2f1c4d8e9b3a7f6c2d8e1f4a9b6c3d8e5f2a1b4c7d6e9f8a3b2c1d4e5f6a7b8';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    private JwtService $jwtService;
    private UserRepository $userRepository;
    private RefreshTokenHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['JWT_SECRET_KEY', 'JWT_SECRET_KEY_OLD', 'AUTH_REFRESH_TOKEN_TTL'] as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
        }
        putenv('JWT_SECRET_KEY=' . self::STRONG_SECRET);

        $this->jwtService = new JwtService();
        $logger = LoggerFactory::create('test.auth.refresh');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));
        $this->handler = new RefreshTokenHandler($this->jwtService, $this->userRepository);
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

    public function test_throws_when_refresh_token_user_no_longer_exists(): void
    {
        // Issue a refresh token for a real user, then delete the user so
        // the handler reaches the "User not found" guard with a valid token.
        $user = $this->persistUser('vanished@example.com');
        $refreshToken = $this->jwtService->generateRefreshToken($user);

        $this->assertTrue($this->userRepository->delete($user->getId() ?? -1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->handler->handle(new RefreshTokenCommand(refreshToken: $refreshToken));
    }

    public function test_rejects_access_token_when_used_as_refresh(): void
    {
        $user = $this->persistUser('wrong-type@example.com');
        $accessToken = $this->jwtService->generateAccessToken($user);

        $this->expectException(\Throwable::class);

        $this->handler->handle(new RefreshTokenCommand(refreshToken: $accessToken));
    }

    private function persistUser(string $email): User
    {
        $user = User::create(
            name: UserName::fromString('Refresh User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext('StrongP@ssw0rd!'),
            role: UserRole::Customer,
        );

        $id = $this->userRepository->save($user);
        $reloaded = $this->userRepository->findById($id);
        $this->assertNotNull($reloaded);

        return $reloaded;
    }
}

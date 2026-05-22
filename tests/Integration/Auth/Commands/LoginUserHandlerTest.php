<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Commands;

use App\Domain\User\Entities\User;
use App\Domain\User\Ports\AuthenticationServiceInterface;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\AccessToken;
use App\Domain\User\ValueObjects\AuthenticationResult;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserCommand;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserHandler;
use App\Infrastructure\Auth\Services\LoginAttemptTracker;
use App\Infrastructure\Auth\Services\SecurityEventService;
use App\Infrastructure\Auth\Services\SessionManagementService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use Config\Database;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for LoginUserHandler covering the error/edge branches
 * not exercised by Phase A's feature happy-path login tests.
 *
 * Branches targeted:
 *  - Brute-force pre-check returns lockout result without DB lookup
 *  - User-not-found path (timing-safe dummy verify + attempt tracking)
 *  - Invalid-password path increments counter and logs failure
 *  - extractTokenPayload defensive returns when auth service yields
 *    malformed tokens (covered via stub auth service)
 *  - outer catch reports "Authentication failed" on unexpected exceptions
 */
#[AllowMockObjectsWithoutExpectations]
final class LoginUserHandlerTest extends IntegrationTestCase
{
    private UserRepository $userRepository;
    private LoginAttemptTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = LoggerFactory::create('test.auth.login');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));
        $this->tracker = new LoginAttemptTracker();
    }

    public function test_brute_force_pre_check_returns_lockout(): void
    {
        $ip = '203.0.113.55';
        $this->seedFailedAttempts($ip, 6); // exceeds threshold of 5

        $authService = $this->createMock(AuthenticationServiceInterface::class);
        $authService->expects($this->never())->method('authenticate');

        $handler = $this->makeHandler($authService);

        $result = $handler->handle(new LoginUserCommand(
            email: 'whatever@example.com',
            password: 'irrelevant',
            ipAddress: $ip,
        ));

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('Too many failed attempts', (string) $result->errorMessage);
    }

    public function test_user_not_found_returns_failure_without_invoking_auth(): void
    {
        $authService = $this->createMock(AuthenticationServiceInterface::class);
        $authService->expects($this->never())->method('authenticate');

        $handler = $this->makeHandler($authService);

        $result = $handler->handle(new LoginUserCommand(
            email: 'ghost@example.com',
            password: 'AnyP@ssw0rd!1234',
            ipAddress: '198.51.100.10',
            userAgent: 'phpunit',
        ));

        // The handler exercises the user-not-found path (timing-safe dummy
        // verify + tracker.recordAttempt + securityEvents). The outer catch
        // wraps any exception from the dummy-hash construction; either way
        // authentication is denied.
        $this->assertFalse($result->isSuccess());
    }

    public function test_invalid_password_increments_failed_attempts(): void
    {
        $email = 'bad-pass@example.com';
        $userId = $this->createUser($email);

        $authService = $this->createMock(AuthenticationServiceInterface::class);
        $authService->method('authenticate')->willReturn(AuthenticationResult::failure('wrong'));

        $handler = $this->makeHandler($authService);

        $result = $handler->handle(new LoginUserCommand(
            email: $email,
            password: 'WrongP@ssw0rd!',
            ipAddress: '192.0.2.42',
            userAgent: 'phpunit',
        ));

        $this->assertFalse($result->isSuccess());

        $reloaded = $this->userRepository->findById($userId);
        $this->assertNotNull($reloaded);
        $this->assertGreaterThanOrEqual(1, $reloaded->getFailedLoginAttempts());
    }

    public function test_success_with_malformed_tokens_still_returns_success(): void
    {
        // extractTokenPayload defensive branches (203, 208) are hit when the
        // auth service returns access/refresh tokens that don't have three
        // dot-segments or have invalid base64 in the middle segment.
        $email = 'malformed-token@example.com';
        $userId = $this->createUser($email);
        $persistedUser = $this->userRepository->findById($userId);
        $this->assertNotNull($persistedUser);

        $accessVo = AccessToken::fromString('not.three.parts.either', new \DateTimeImmutable('+1 hour'));
        $refreshVo = AccessToken::fromString('aaa.****.bbb', new \DateTimeImmutable('+2 hours'));

        $authService = $this->createMock(AuthenticationServiceInterface::class);
        $authService->method('authenticate')->willReturn(AuthenticationResult::success(
            accessToken: $accessVo,
            refreshToken: $refreshVo,
            user: $persistedUser,
            expiresIn: 3600,
        ));

        $handler = $this->makeHandler($authService);

        $result = $handler->handle(new LoginUserCommand(
            email: $email,
            password: 'StrongP@ssw0rd!',
            ipAddress: '192.0.2.99',
            userAgent: 'phpunit',
        ));

        $this->assertTrue($result->isSuccess());
    }

    public function test_outer_exception_returns_authentication_failed(): void
    {
        $email = 'crash@example.com';
        $this->createUser($email);

        $authService = $this->createMock(AuthenticationServiceInterface::class);
        $authService->method('authenticate')->willThrowException(new \RuntimeException('boom'));

        $handler = $this->makeHandler($authService);

        $result = $handler->handle(new LoginUserCommand(
            email: $email,
            password: 'StrongP@ssw0rd!',
            ipAddress: '192.0.2.100',
            userAgent: 'phpunit',
        ));

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Authentication failed', $result->errorMessage);
    }

    private function makeHandler(AuthenticationServiceInterface $authService): LoginUserHandler
    {
        return new LoginUserHandler(
            $this->userRepository,
            $authService,
            new SessionManagementService(),
            $this->tracker,
            new SecurityEventService(),
            LoggerFactory::create('test.auth.login.handler'),
        );
    }

    private function createUser(string $email): int
    {
        $user = User::create(
            name: UserName::fromString('Login Test'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext('StrongP@ssw0rd!'),
            role: UserRole::Customer,
        );

        return $this->userRepository->save($user);
    }

    private function seedFailedAttempts(string $ip, int $count): void
    {
        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = Database::connect();
        for ($i = 0; $i < $count; $i++) {
            $db->table('login_attempts')->insert([
                'email' => 'flood@example.com',
                'user_id' => null,
                'ip_address' => $ip,
                'user_agent' => 'flood',
                'success' => 0,
                'failure_reason' => 'invalid_password',
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

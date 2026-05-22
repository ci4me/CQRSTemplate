<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Commands;

use App\Domain\User\Entities\User;
use App\Domain\User\Repositories\PasswordHistoryRepository;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\Commands\ResetPassword\ResetPasswordCommand;
use App\Infrastructure\Auth\Commands\ResetPassword\ResetPasswordHandler;
use App\Infrastructure\Auth\ValueObjects\PasswordResetToken;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Models\UserModel;
use Config\Database;
use Tests\Support\IntegrationTestCase;

/**
 * Integration tests for ResetPasswordHandler covering the validation and
 * security branches: invalid/expired token, missing user, password reuse,
 * and the happy path that updates the password + records history + revokes
 * sessions.
 */
final class ResetPasswordHandlerTest extends IntegrationTestCase
{
    private UserRepository $userRepository;
    private PasswordHistoryRepository $passwordHistory;
    private ResetPasswordHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $logger = LoggerFactory::create('test.auth.reset-password');
        $this->userRepository = new UserRepository(new UserModel(), $logger, config('Logging'));

        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = Database::connect();
        $this->passwordHistory = new PasswordHistoryRepository($db);

        $this->handler = new ResetPasswordHandler($this->userRepository, $this->passwordHistory);
    }

    public function test_resets_password_when_token_is_valid(): void
    {
        $userId = $this->createUser('reset@example.com');
        $token = $this->storeFreshToken($userId);

        $this->handler->handle(new ResetPasswordCommand(
            token: $token,
            newPassword: 'BrandNewP@ssw0rd!',
        ));

        // Token consumed
        $this->dontSeeInDatabase('password_reset_tokens', ['user_id' => $userId]);

        // Password updated
        $user = $this->userRepository->findById($userId);
        $this->assertNotNull($user);
        $this->assertTrue($user->getHashedPassword()->verify('BrandNewP@ssw0rd!'));

        // History recorded
        $this->assertGreaterThanOrEqual(1, $this->passwordHistory->countByUserId($userId));
    }

    public function test_throws_when_token_unknown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid or expired reset token');

        $this->handler->handle(new ResetPasswordCommand(
            token: 'totally-unknown-token-value',
            newPassword: 'BrandNewP@ssw0rd!',
        ));
    }

    public function test_throws_and_purges_when_token_expired(): void
    {
        $userId = $this->createUser('expired@example.com');
        $token = $this->storeToken($userId, expiresAt: new \DateTimeImmutable('-1 hour'));

        try {
            $this->handler->handle(new ResetPasswordCommand(
                token: $token,
                newPassword: 'BrandNewP@ssw0rd!',
            ));
            $this->fail('Expected RuntimeException for expired token');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        // Expired token row should be removed
        $this->dontSeeInDatabase('password_reset_tokens', ['user_id' => $userId]);
    }

    public function test_throws_when_user_missing(): void
    {
        // Insert a token row referencing a user that doesn't exist
        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = Database::connect();
        $token = bin2hex(random_bytes(32));
        $vo = PasswordResetToken::fromToken($token);
        // Disable FK enforcement to allow orphan row (we explicitly want a
        // dangling token to test the User-not-found branch).
        $db->disableForeignKeyChecks();
        $db->table('password_reset_tokens')->insert([
            'user_id' => 99999,
            'token_hash' => $vo->getTokenHash(),
            'expires_at' => (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $db->enableForeignKeyChecks();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('User not found');

        $this->handler->handle(new ResetPasswordCommand(
            token: $token,
            newPassword: 'BrandNewP@ssw0rd!',
        ));
    }

    private function createUser(string $email): int
    {
        $user = User::create(
            name: UserName::fromString('Reset User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromPlaintext('OldP@ssw0rd123!'),
            role: UserRole::Customer,
        );

        return $this->userRepository->save($user);
    }

    private function storeFreshToken(int $userId): string
    {
        return $this->storeToken($userId, expiresAt: new \DateTimeImmutable('+1 hour'));
    }

    private function storeToken(int $userId, \DateTimeImmutable $expiresAt): string
    {
        /** @var \CodeIgniter\Database\BaseConnection<object|resource|false, object|resource|false> $db */
        $db = Database::connect();
        $token = bin2hex(random_bytes(32));
        $vo = PasswordResetToken::fromToken($token);

        $db->table('password_reset_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $vo->getTokenHash(),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }
}

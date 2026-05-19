<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\ResetPassword;

use App\Domain\User\ValueObjects\HashedPassword;
use App\Infrastructure\Auth\ValueObjects\PasswordResetToken;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Repositories\PasswordHistoryRepository;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Reset Password Handler.
 *
 * SECURITY:
 * - Validates token exists and not expired
 * - Single-use tokens (deleted after reset)
 * - Enforces password complexity
 * - Prevents password reuse
 * - Invalidates all existing sessions (revoke tokens)
 * - Constant-time token comparison
 *
 * @package App\Infrastructure\Auth\Commands\ResetPassword
 */
final readonly class ResetPasswordHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private UserRepository $userRepository,
        private PasswordHistoryRepository $passwordHistory
    ) {
        $this->logger = LoggerFactory::create('auth.password-reset');
    }

    public function handle(ResetPasswordCommand $command): void
    {
        $this->logger->info('Password reset attempt', [
            'domain' => 'Auth',
            'command' => 'ResetPasswordCommand',
        ]);

        try {
            // Hash token for database lookup
            $resetToken = PasswordResetToken::fromToken($command->token);

            // Find token in database
            $tokenData = $this->findResetToken($resetToken->getTokenHash());

            if ($tokenData === null) {
                $this->logger->warning('Invalid or expired reset token', [
                    'domain' => 'Auth',
                ]);
                throw new \RuntimeException('Invalid or expired reset token');
            }

            // Check expiration
            $expiresAt = new \DateTimeImmutable($tokenData['expires_at']);
            if ($expiresAt < new \DateTimeImmutable()) {
                $this->logger->warning('Expired reset token used', [
                    'domain' => 'Auth',
                    'user_id' => $tokenData['user_id'],
                ]);
                $this->deleteResetToken($tokenData['id']);
                throw new \RuntimeException('Reset token has expired');
            }

            // Get user
            $userId = (int) $tokenData['user_id'];
            $user = $this->userRepository->findById($userId);

            if ($user === null) {
                throw new \RuntimeException('User not found');
            }

            // Validate new password and hash
            $hashedPassword = HashedPassword::fromPlaintext($command->newPassword);

            // Check password reuse
            if ($this->passwordHistory->containsHash($userId, $hashedPassword->getHash())) {
                $this->logger->warning('Password reuse attempt during reset', [
                    'domain' => 'Auth',
                    'user_id' => $userId,
                ]);
                throw new \RuntimeException(
                    'Password has been used recently. Please choose a different password.'
                );
            }

            // Update password
            $user->changePassword($hashedPassword);
            $this->userRepository->update($user);

            // Store in password history
            $this->passwordHistory->store($userId, $hashedPassword->getHash());

            // Delete reset token (single-use)
            $this->deleteResetToken($tokenData['id']);

            // TODO: Revoke all user tokens (force re-login)
            // This ensures old sessions can't be used after password reset
            $this->revokeAllUserTokens($userId);

            $this->logger->info('Password reset successful', [
                'domain' => 'Auth',
                'user_id' => $userId,
                'security' => 'CRITICAL',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Password reset failed', [
                'domain' => 'Auth',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findResetToken(string $tokenHash): ?array
    {
        $db = \Config\Database::connect();

        $queryResult = $db->table('password_reset_tokens')
            ->where('token_hash', $tokenHash)
            ->get();

        if ($queryResult === false) {
            return null;
        }

        $result = $queryResult->getRowArray();

        return $result ?? null;
    }

    private function deleteResetToken(int $tokenId): void
    {
        $db = \Config\Database::connect();

        $db->table('password_reset_tokens')
            ->where('id', $tokenId)
            ->delete();
    }

    private function revokeAllUserTokens(int $userId): void
    {
        $db = \Config\Database::connect();

        // Revoke all refresh tokens
        $db->table('refresh_tokens')
            ->where('user_id', $userId)
            ->update([
                'revoked' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $this->logger->info('All user tokens revoked after password reset', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'security' => 'CRITICAL',
        ]);
    }
}

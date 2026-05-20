<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\RequestPasswordReset;

use App\Domain\User\ValueObjects\Email;
use App\Infrastructure\Auth\ValueObjects\PasswordResetToken;
use App\Infrastructure\Email\EmailService;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use Psr\Log\LoggerInterface;

/**
 * Request Password Reset Handler.
 *
 * SECURITY:
 * - Rate limited (5 requests per 5 minutes per email)
 * - Token expiration (1 hour)
 * - Single-use tokens (deleted after reset)
 * - No user enumeration (same response for valid/invalid email)
 * - SHA-256 hashed tokens stored in database
 * - Email sending implemented (CR-6.1)
 *
 * @package App\Infrastructure\Auth\Commands\RequestPasswordReset
 */
final readonly class RequestPasswordResetHandler
{
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param UserRepository $userRepository
     * @param EmailService   $emailService
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailService $emailService
    ) {
        $this->logger = LoggerFactory::create('auth.password-reset');
    }

    /**
     * handle.
     *
     * @param RequestPasswordResetCommand $command
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function handle(RequestPasswordResetCommand $command): void
    {
        $this->logger->info('Password reset requested', [
            'domain' => 'Auth',
            'command' => 'RequestPasswordResetCommand',
            'email' => $command->email,
        ]);

        try {
            // Find user by email
            $email = Email::fromString($command->email);
            $user = $this->userRepository->findByEmail($email);

            // SECURITY: No user enumeration - same response for valid/invalid email
            if ($user === null) {
                $this->logger->warning('Password reset requested for non-existent email', [
                    'domain' => 'Auth',
                    'email' => $command->email,
                ]);
                return; // Silent failure
            }

            $userId = $user->getId();
            assert($userId !== null);

            // Delete any existing reset tokens for this user
            $this->deleteExistingTokens($userId);

            // Generate new reset token
            $resetToken = PasswordResetToken::generate();

            // Store token in database (hashed)
            $this->storeResetToken($userId, $resetToken);

            // SECURITY: Send password reset email with secure link (CR-6.1)
            $baseUrlEnv = getenv('app.baseURL');
            $baseUrl = $baseUrlEnv !== false ? $baseUrlEnv : 'http://localhost:8080';
            $emailSent = $this->emailService->sendPasswordResetEmail(
                $command->email,
                $resetToken->getToken(),
                $baseUrl
            );

            if ($emailSent) {
                $this->logger->info('Password reset email sent successfully', [
                    'domain' => 'Auth',
                    'user_id' => $user->getId(),
                    'token_hash' => hash('sha256', $resetToken->getToken()), // SECURITY: Log hash, not plaintext
                    'expires_in_minutes' => 60,
                ]);
            } else {
                $this->logger->error('Failed to send password reset email', [
                    'domain' => 'Auth',
                    'user_id' => $user->getId(),
                    'email' => $command->email,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Password reset request failed', [
                'domain' => 'Auth',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            // Silent failure - don't reveal errors to user
        }
    }

    /**
     * deleteExistingTokens.
     *
     * @param int $userId
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function deleteExistingTokens(int $userId): void
    {
        $db = \Config\Database::connect();

        $db->table('password_reset_tokens')
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * storeResetToken.
     *
     * @param int                $userId
     * @param PasswordResetToken $token
     * @return void
     * @todo Auto-generated docblock — review and replace this description.
     */
    private function storeResetToken(int $userId, PasswordResetToken $token): void
    {
        $db = \Config\Database::connect();

        $expiresAt = new \DateTimeImmutable('+1 hour');

        $db->table('password_reset_tokens')->insert([
            'user_id' => $userId,
            'token_hash' => $token->getTokenHash(),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

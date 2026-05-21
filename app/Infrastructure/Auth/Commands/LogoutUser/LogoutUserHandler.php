<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LogoutUser;

use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Auth\Services\SessionManagementService;
use Psr\Log\LoggerInterface;

/**
 * LogoutUserHandler.
 */
final readonly class LogoutUserHandler
{
    /**
     * __construct.
     *
     * @param TokenBlacklistInterface  $blacklistService
     * @param SessionManagementService $sessionManager
     * @param LoggerInterface          $logger
     */
    public function __construct(
        private TokenBlacklistInterface $blacklistService,
        private SessionManagementService $sessionManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * handle.
     *
     * @param LogoutUserCommand $command
     * @return void
     */
    public function handle(LogoutUserCommand $command): void
    {
        // SECURITY: Blacklist access token (prevent immediate reuse)
        $this->blacklistService->blacklist($command->token);

        // SECURITY: Blacklist refresh token (prevent token refresh after logout - CR-2.1)
        if ($command->refreshToken !== null && $command->refreshToken !== '') {
            $this->blacklistService->blacklist($command->refreshToken);
        }

        // SECURITY: Revoke session record (complete logout - CR-2.1, task_3.2)
        if ($command->userId !== null) {
            $accessJti = $this->extractJti($command->token);
            if ($accessJti !== null) {
                $this->sessionManager->revokeSessionByAccessJti($accessJti, $command->userId);
            }
        }

        $this->logger->info('User logged out completely', [
            'domain' => 'User',
            'command' => 'LogoutUserCommand',
            'user_id' => $command->userId,
            'refresh_token_blacklisted' => $command->refreshToken !== null,
            'session_revoked' => $command->userId !== null,
        ]);
    }

    /**
     * Extract JTI (JWT ID) from token.
     *
     * @param string $token JWT token
     * @return string|null JTI or null if extraction fails
     */
    private function extractJti(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return null;
        }

        $data = json_decode($payload, true);
        return $data['jti'] ?? null;
    }
}

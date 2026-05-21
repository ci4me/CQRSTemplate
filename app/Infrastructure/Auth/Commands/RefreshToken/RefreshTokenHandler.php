<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\RefreshToken;

use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Domain\User\Repositories\UserRepository;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\ValueObjects\AuthenticationResult;
use App\Infrastructure\Logging\LoggerFactory;
use Psr\Log\LoggerInterface;

/**
 * Refresh Token Handler.
 *
 * SECURITY: Token Rotation Strategy
 * ==================================
 * Single-use refresh tokens prevent replay attacks:
 *
 * 1. Validate refresh token signature and expiration
 * 2. Check token type is 'refresh' (not access)
 * 3. Check token not already used (database lookup)
 * 4. Mark old refresh token as revoked
 * 5. Generate NEW access + refresh tokens
 * 6. Store new refresh token in database
 * 7. Return new tokens to client
 *
 * If token already revoked → possible token theft → revoke ALL user tokens
 *
 * @package App\Infrastructure\Auth\Commands\RefreshToken
 */
final readonly class RefreshTokenHandler
{
    /** @var LoggerInterface */
    private LoggerInterface $logger;

    /**
     * __construct.
     *
     * @param JwtService                   $jwtService
     * @param UserRepository               $userRepository
     * @param TokenBlacklistInterface|null $blacklist
     */
    public function __construct(
        private JwtService $jwtService,
        private UserRepository $userRepository,
        private ?TokenBlacklistInterface $blacklist = null
    ) {
        $this->logger = LoggerFactory::create('auth.refresh-token');
    }

    /**
     * handle.
     *
     * @param RefreshTokenCommand $command
     * @return AuthenticationResult
     * @throws \RuntimeException
     */
    public function handle(RefreshTokenCommand $command): AuthenticationResult
    {
        $this->logger->info('Processing refresh token request', [
            'domain' => 'Auth',
            'command' => 'RefreshTokenCommand',
        ]);

        try {
            // SECURITY: an explicit logout blacklists the refresh token along
            // with the access token. Consulting the blacklist BEFORE the
            // refresh_tokens table makes rotation reject a stolen-but-revoked
            // refresh token even when the per-jti row hasn't yet been marked
            // (e.g. logout writes only the blacklist row, the per-token
            // revocation column races with the new refresh).
            if ($this->blacklist !== null && $this->blacklist->isBlacklisted($command->refreshToken)) {
                $this->logger->warning('Refresh attempt with blacklisted token', [
                    'domain' => 'Auth',
                    'security' => 'CRITICAL',
                ]);
                throw new \RuntimeException('Token has been revoked');
            }

            // Validate refresh token signature and expiration
            $payload = $this->jwtService->validateToken($command->refreshToken);

            // Verify token type
            if (!isset($payload['type']) || $payload['type'] !== 'refresh') {
                $this->logger->warning('Invalid token type for refresh', [
                    'domain' => 'Auth',
                    'token_type' => $payload['type'] ?? 'missing',
                ]);
                throw new \RuntimeException('Invalid token type');
            }

            // Get user
            $userId = (int) ($payload['user_id'] ?? 0);
            $user = $this->userRepository->findById($userId);

            if ($user === null) {
                $this->logger->error('User not found for refresh token', [
                    'domain' => 'Auth',
                    'user_id' => $userId,
                ]);
                throw new \RuntimeException('User not found');
            }

            // Check if refresh token is revoked or already used
            $jti = $payload['jti'] ?? null;
            if ($jti !== null && $this->isRefreshTokenRevoked($jti)) {
                $this->logger->critical('Revoked refresh token reuse detected', [
                    'domain' => 'Auth',
                    'user_id' => $userId,
                    'jti' => $jti,
                    'security' => 'CRITICAL',
                ]);

                // SECURITY: Possible token theft - revoke all user tokens
                $this->revokeAllUserTokens($userId);
                throw new \RuntimeException('Token has been revoked');
            }

            // Mark old refresh token as revoked
            if ($jti !== null) {
                $this->revokeRefreshToken($jti);
            }

            // Generate new token pair (rotation)
            $accessToken = $this->jwtService->generateAccessToken($user);
            $newRefreshToken = $this->jwtService->generateRefreshToken($user);

            // Store new refresh token
            $newPayload = $this->jwtService->getTokenPayload($newRefreshToken);
            $newJti = $newPayload['jti'] ?? null;
            $expiresAt = $newPayload['exp'] ?? 0;

            if ($newJti !== null) {
                $this->storeRefreshToken($userId, $newJti, $expiresAt);
            }

            $this->logger->info('Refresh token rotated successfully', [
                'domain' => 'Auth',
                'user_id' => $userId,
                'old_jti' => $jti,
                'new_jti' => $newJti,
            ]);

            return AuthenticationResult::create(
                $user,
                $accessToken,
                $newRefreshToken,
                $newPayload['exp'] ?? 0
            );
        } catch (\Throwable $e) {
            $this->logger->error('Refresh token failed', [
                'domain' => 'Auth',
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * isRefreshTokenRevoked.
     *
     * @param string $jti
     * @return bool
     */
    private function isRefreshTokenRevoked(string $jti): bool
    {
        $db = \Config\Database::connect();

        $result = $db->table('refresh_tokens')
            ->where('jti', $jti)
            ->where('revoked', true)
            ->countAllResults();

        return $result > 0;
    }

    /**
     * revokeRefreshToken.
     *
     * @param string $jti
     * @return void
     */
    private function revokeRefreshToken(string $jti): void
    {
        $db = \Config\Database::connect();

        $db->table('refresh_tokens')
            ->where('jti', $jti)
            ->update([
                'revoked' => true,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * storeRefreshToken.
     *
     * @param int    $userId
     * @param string $jti
     * @param int    $expiresAt
     * @return void
     */
    private function storeRefreshToken(int $userId, string $jti, int $expiresAt): void
    {
        $db = \Config\Database::connect();

        $db->table('refresh_tokens')->insert([
            'user_id' => $userId,
            'jti' => $jti,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'revoked' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * revokeAllUserTokens.
     *
     * @param int $userId
     * @return void
     */
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

        $this->logger->warning('All user tokens revoked due to security incident', [
            'domain' => 'Auth',
            'user_id' => $userId,
            'security' => 'CRITICAL',
        ]);
    }
}

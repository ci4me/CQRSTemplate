<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Adapters\Jwt;

use App\Domain\User\Entities\User;
use App\Domain\User\Ports\AuthenticationServiceInterface;
use App\Domain\User\ValueObjects\AccessToken;
use App\Domain\User\ValueObjects\AuthenticationResult;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\Services\TokenBlacklistService;
use App\Infrastructure\Persistence\Repositories\UserRepository;

final readonly class FirebaseJwtAdapter implements AuthenticationServiceInterface
{
    public function __construct(
        private JwtService $jwtService,
        private TokenBlacklistService $blacklist,
        private UserRepository $userRepository,
    ) {
    }

    public function authenticate(User $user, string $password): AuthenticationResult
    {
        if (!$user->getHashedPassword()->verify($password)) {
            return AuthenticationResult::failure('Invalid credentials');
        }

        if (!$user->isActive()) {
            return AuthenticationResult::failure('Account is inactive');
        }

        if ($user->isLockedOut()) {
            return AuthenticationResult::failure('Account is locked');
        }

        $accessTokenString = $this->jwtService->generateAccessToken($user);
        $refreshTokenString = $this->jwtService->generateRefreshToken($user);

        $expiresIn = getenv('AUTH_TOKEN_TTL') !== false ? (int) getenv('AUTH_TOKEN_TTL') : 3600;
        $accessToken = AccessToken::fromString($accessTokenString, new \DateTimeImmutable('+' . $expiresIn . ' seconds'));
        $refreshToken = AccessToken::fromString($refreshTokenString, new \DateTimeImmutable('+30 days'));

        return AuthenticationResult::success($accessToken, $refreshToken, $user, $expiresIn);
    }

    public function validateToken(string $token): ?User
    {
        if ($this->blacklist->isBlacklisted($token)) {
            return null;
        }

        try {
            $payload = $this->jwtService->validateToken($token, 'access');
            $userId = $payload['user_id'] ?? null;

            if ($userId === null) {
                return null;
            }

            return $this->userRepository->findById($userId);
        } catch (\Throwable) {
            return null;
        }
    }

    public function generateToken(User $user): AccessToken
    {
        $tokenString = $this->jwtService->generateAccessToken($user);
        $expiresIn = getenv('AUTH_TOKEN_TTL') !== false ? (int) getenv('AUTH_TOKEN_TTL') : 3600;
        return AccessToken::fromString($tokenString, new \DateTimeImmutable('+' . $expiresIn . ' seconds'));
    }
}

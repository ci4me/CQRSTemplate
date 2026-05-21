<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Commands\LoginUser;

use App\Domain\User\Ports\AuthenticationServiceInterface;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\AuthenticationResult;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Infrastructure\Auth\Services\LoginAttemptTracker;
use App\Infrastructure\Auth\Services\SecurityEventService;
use App\Infrastructure\Auth\Services\SessionManagementService;
use Psr\Log\LoggerInterface;

/**
 * LoginUserHandler.
 */
final readonly class LoginUserHandler
{
    /**
     * __construct.
     *
     * @param UserRepository                 $userRepository
     * @param AuthenticationServiceInterface $authService
     * @param SessionManagementService       $sessionManager
     * @param LoginAttemptTracker            $loginAttemptTracker
     * @param SecurityEventService           $securityEvents
     * @param LoggerInterface                $logger
     */
    public function __construct(
        private UserRepository $userRepository,
        private AuthenticationServiceInterface $authService,
        private SessionManagementService $sessionManager,
        private LoginAttemptTracker $loginAttemptTracker,
        private SecurityEventService $securityEvents,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * handle.
     *
     * @param LoginUserCommand $command
     * @return AuthenticationResult
     */
    public function handle(LoginUserCommand $command): AuthenticationResult
    {
        $ipAddress = $command->ipAddress ?? '0.0.0.0';
        $userAgent = $command->userAgent ?? 'unknown';

        // SECURITY: Check for brute force attack
        if ($this->loginAttemptTracker->isBruteForceDetected($ipAddress)) {
            $this->securityEvents->logSuspiciousActivity(
                null,
                $ipAddress,
                'Brute force attack detected - too many failed login attempts',
                ['email' => $command->email, 'user_agent' => $userAgent]
            );

            return AuthenticationResult::failure('Too many failed attempts. Please try again later.');
        }

        $this->logger->info('User login attempt', [
            'domain' => 'User',
            'command' => 'LoginUserCommand',
            'email' => $command->email,
            'ip_address' => $ipAddress,
        ]);

        try {
            $email = Email::fromString($command->email);
            $user = $this->userRepository->findByEmail($email);

            if ($user === null) {
                // SECURITY: Timing Attack Mitigation (OWASP A02 - Cryptographic Failures)
                $dummyHash = HashedPassword::fromPlaintext('dummy_password_' . bin2hex(random_bytes(8)));
                $dummyHash->verify($command->password); // Takes ~100ms with Argon2ID

                // Track failed login attempt
                $this->loginAttemptTracker->recordAttempt(
                    $command->email,
                    null,
                    $ipAddress,
                    $userAgent,
                    false,
                    'user_not_found'
                );

                $this->securityEvents->logLoginFailure(
                    $command->email,
                    $ipAddress,
                    'User not found',
                    $userAgent
                );

                $this->logger->warning('Login failed - user not found', [
                    'domain' => 'User',
                    'email' => $command->email,
                ]);

                return AuthenticationResult::failure('Invalid credentials');
            }

            $userId = $user->getId();
            assert($userId !== null);

            $result = $this->authService->authenticate($user, $command->password);

            if ($result->isSuccess()) {
                // SECURITY: Regenerate session ID to prevent session fixation attacks (OWASP A07)
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }

                $user->resetFailedLoginAttempts();
                $this->userRepository->update($user);

                // Track successful login
                $this->loginAttemptTracker->recordAttempt(
                    $command->email,
                    $userId,
                    $ipAddress,
                    $userAgent,
                    true,
                    null
                );

                $this->securityEvents->logLoginSuccess($userId, $ipAddress, $userAgent);

                // Create session record
                $accessToken = $result->accessToken;
                $refreshToken = $result->refreshToken;

                if ($accessToken !== null && $refreshToken !== null) {
                    $accessPayload = $this->extractTokenPayload($accessToken->getValue());
                    $refreshPayload = $this->extractTokenPayload($refreshToken->getValue());

                    $this->sessionManager->createSession(
                        $userId,
                        $accessPayload['jti'] ?? '',
                        $refreshPayload['jti'] ?? '',
                        $ipAddress,
                        $userAgent,
                        $refreshPayload['exp'] ?? time() + 86400
                    );
                }

                $this->logger->info('User logged in successfully', [
                    'domain' => 'User',
                    'user_id' => $userId,
                    'ip_address' => $ipAddress,
                ]);
            } else {
                $user->incrementFailedLoginAttempts();
                $this->userRepository->update($user);

                // Track failed login attempt
                $this->loginAttemptTracker->recordAttempt(
                    $command->email,
                    $userId,
                    $ipAddress,
                    $userAgent,
                    false,
                    'invalid_password'
                );

                $this->securityEvents->logLoginFailure(
                    $command->email,
                    $ipAddress,
                    'Invalid password',
                    $userAgent
                );

                $this->logger->warning('Login failed - invalid credentials', [
                    'domain' => 'User',
                    'user_id' => $userId,
                    'failed_attempts' => $user->getFailedLoginAttempts(),
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Login failed with exception', [
                'domain' => 'User',
                'exception' => $e->getMessage(),
            ]);
            return AuthenticationResult::failure('Authentication failed');
        }
    }

    /**
     * Extract payload from JWT token.
     *
     * @param string $token JWT token
     * @return array<string, mixed>
     */
    private function extractTokenPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            return [];
        }

        return json_decode($payload, true) ?? [];
    }
}

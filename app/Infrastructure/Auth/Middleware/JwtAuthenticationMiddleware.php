<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Middleware;

use App\Domain\User\Entities\User;
use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\Services\SessionManagementService;
use App\Infrastructure\Persistence\Repositories\UserRepository;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * JWT Authentication Middleware.
 *
 * Validates JWT tokens from Authorization header and attaches authenticated
 * user to the request. Implements comprehensive security validation and logging.
 *
 * Security Validations:
 * 1. Authorization header present and correctly formatted (Bearer {token})
 * 2. Token signature is valid (cryptographic verification)
 * 3. Token has not expired (timestamp check)
 * 4. Token is not blacklisted (logged out tokens)
 * 5. User associated with token exists in database
 * 6. User account is active (not suspended/deleted)
 *
 * Error Handling:
 * - 401 Unauthorized with specific error codes for different failure scenarios
 * - Comprehensive security event logging for audit trails
 * - No sensitive information in error responses (security best practice)
 *
 * Usage:
 * Apply this filter to routes requiring authentication in Config/Filters.php
 *
 * Example:
 * ```php
 * $routes->group('api', ['filter' => 'jwt'], function ($routes) {
 *     $routes->get('profile', 'ProfileController::show');
 * });
 * ```
 *
 * @package App\Infrastructure\Auth\Middleware
 */
final class JwtAuthenticationMiddleware implements FilterInterface
{
    private JwtService $jwtService;
    private TokenBlacklistInterface $blacklistService;
    private UserRepository $userRepository;
    private SessionManagementService $sessionManager;
    private LoggerInterface $logger;

    public function __construct(
        ?JwtService $jwtService = null,
        ?TokenBlacklistInterface $blacklistService = null,
        ?UserRepository $userRepository = null,
        ?SessionManagementService $sessionManager = null,
        ?LoggerInterface $logger = null
    ) {
        $this->jwtService = $jwtService ?? \Config\Services::jwtService();
        $this->blacklistService = $blacklistService ?? \Config\Services::tokenBlacklistService();
        $this->userRepository = $userRepository ?? \Config\Services::userRepository();
        $this->sessionManager = $sessionManager ?? \Config\Services::sessionManagementService();
        $this->logger = $logger ?? \Config\Services::logger();
    }

    /**
     * Execute middleware before controller.
     *
     * Validates JWT token and attaches authenticated user to request.
     *
     * @param RequestInterface $request HTTP request
     * @param mixed $arguments Optional middleware arguments
     * @return RequestInterface|ResponseInterface Modified request with user, or 401 response
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function before(RequestInterface $request, mixed $arguments = null): RequestInterface|ResponseInterface
    {
        // Step 1: Extract Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if ($authHeader === '') {
            return $this->unauthorizedResponse(
                'missing_authorization_header',
                'Authorization header is required',
                ['ip' => $request->getIPAddress()]
            );
        }

        // Step 2: Validate Bearer token format
        if (!$this->isBearerTokenFormat($authHeader)) {
            return $this->unauthorizedResponse(
                'invalid_authorization_format',
                'Authorization header must be in format: Bearer {token}',
                ['header' => $this->maskToken($authHeader), 'ip' => $request->getIPAddress()]
            );
        }

        // Step 3: Extract token
        $token = $this->extractToken($authHeader);

        // Step 4: Validate token signature and expiration
        try {
            $payload = $this->jwtService->validateToken($token, 'access');
        } catch (\Exception $e) {
            return $this->unauthorizedResponse(
                'invalid_token_signature',
                'Token signature is invalid or token has expired',
                ['exception' => $e->getMessage(), 'ip' => $request->getIPAddress()]
            );
        }

        // Step 5: Check if token is blacklisted (logged out)
        if ($this->blacklistService->isBlacklisted($token)) {
            return $this->unauthorizedResponse(
                'token_blacklisted',
                'Token has been revoked',
                ['user_id' => $payload['user_id'] ?? null, 'ip' => $request->getIPAddress()]
            );
        }

        // Step 6: Validate device fingerprint (CR-7.2 - session hijacking prevention)
        $fingerprintValidation = $this->validateDeviceFingerprint($payload, $request);
        if ($fingerprintValidation !== null) {
            return $fingerprintValidation;
        }

        // Step 7: Check idle timeout (HP-5.2 - PCI-DSS requirement)
        $idleTimeoutCheck = $this->checkIdleTimeout($payload);
        if ($idleTimeoutCheck !== null) {
            return $idleTimeoutCheck;
        }

        // Step 8: Extract user_id from payload
        $userId = $payload['user_id'] ?? null;

        if ($userId === null) {
            return $this->unauthorizedResponse(
                'missing_user_id',
                'Token payload is missing user_id',
                ['payload' => array_keys($payload), 'ip' => $request->getIPAddress()]
            );
        }

        // Step 9: Load user from repository
        $user = $this->userRepository->findById((int) $userId);

        if ($user === null) {
            return $this->unauthorizedResponse(
                'user_not_found',
                'User associated with token does not exist',
                ['user_id' => $userId, 'ip' => $request->getIPAddress()]
            );
        }

        // Step 10: Verify user is active
        if (!$user->isActive()) {
            return $this->unauthorizedResponse(
                'user_inactive',
                'User account is not active',
                [
                    'user_id' => $userId,
                    'status' => $user->getStatus()->value,
                    'ip' => $request->getIPAddress(),
                ]
            );
        }

        // Step 11: Attach user to request for controllers
        // PHPStan: Dynamic property assignment to support controllers accessing user
        /** @phpstan-ignore-next-line */
        $request->user = $user;

        // Log successful authentication
        $this->logAuthenticationSuccess($user, $request);

        // Step 12: Update session activity for idle timeout tracking
        $this->updateSessionActivity($payload);

        return $request;
    }

    /**
     * Validate device fingerprint to prevent session hijacking.
     *
     * SECURITY: CR-7.2 - Detects session hijacking by comparing current device
     * fingerprint with the one stored during login.
     *
     * Grace Period: Allows fingerprint changes within configured time window
     * to accommodate dynamic IPs and legitimate device changes.
     *
     * @param array<string, mixed> $payload JWT token payload
     * @param RequestInterface $request HTTP request
     * @return ResponseInterface|null Unauthorized response if validation fails, null if passes
     */
    private function validateDeviceFingerprint(array $payload, RequestInterface $request): ?ResponseInterface
    {
        $accessTokenJti = $payload['jti'] ?? null;
        $userId = $payload['user_id'] ?? null;

        if ($accessTokenJti === null || $userId === null) {
            // Backward compatibility: skip validation for old tokens
            return null;
        }

        try {
            $db = \Config\Database::connect();

            // Fetch session record
            $result = $db->table('sessions')
                ->select('device_fingerprint, created_at')
                ->where('access_token_jti', (string) $accessTokenJti)
                ->where('user_id', (int) $userId)
                ->where('revoked', false)
                ->get();

            if ($result === false) {
                return null; // Session not found - continue (backward compatibility)
            }

            $session = $result->getRowArray();

            if ($session === null) {
                return null; // Session not found - continue (backward compatibility)
            }

            // Generate current device fingerprint
            $userAgent = method_exists($request, 'getUserAgent') ? $request->getUserAgent()->getAgentString() : 'unknown';
            $currentFingerprint = $this->generateDeviceFingerprint(
                $request->getIPAddress(),
                $userAgent
            );

            // Compare fingerprints
            if ($currentFingerprint !== $session['device_fingerprint']) {
                // Check grace period (allow fingerprint changes within X minutes of login)
                $gracePeriodSeconds = (int) (getenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD') !== false ? getenv('AUTH_DEVICE_FINGERPRINT_GRACE_PERIOD') : 300);

                if ($gracePeriodSeconds > 0) {
                    $createdAt = strtotime($session['created_at']);
                    $gracePeriodExpiry = $createdAt + $gracePeriodSeconds;

                    if (time() <= $gracePeriodExpiry) {
                        // Within grace period - allow fingerprint mismatch
                        return null;
                    }
                }

                // Fingerprint mismatch detected - possible session hijacking
                $this->logger->warning('Device fingerprint mismatch detected', [
                    'domain' => 'Auth',
                    'middleware' => 'JwtAuthenticationMiddleware',
                    'user_id' => $userId,
                    'expected_fingerprint' => $session['device_fingerprint'],
                    'actual_fingerprint' => $currentFingerprint,
                    'security' => 'CRITICAL',
                ]);

                return $this->unauthorizedResponse(
                    'device_fingerprint_mismatch',
                    'Session validation failed - device changed',
                    [
                        'user_id' => $userId,
                        'ip' => $request->getIPAddress(),
                        'security' => 'CRITICAL',
                    ]
                );
            }

            return null; // Validation passed
        } catch (\Throwable $e) {
            // Log error but don't fail the request - fingerprint validation is non-critical
            $this->logger->warning('Device fingerprint validation failed with exception', [
                'domain' => 'Auth',
                'middleware' => 'JwtAuthenticationMiddleware',
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check idle timeout to enforce PCI-DSS requirements.
     *
     * SECURITY: HP-5.2 - Automatically revokes sessions after period of inactivity.
     * PCI-DSS 3.2.1 requires 15-minute idle timeout for applications with cardholder data.
     *
     * @param array<string, mixed> $payload JWT token payload
     * @return ResponseInterface|null Unauthorized response if timeout exceeded, null otherwise
     */
    private function checkIdleTimeout(array $payload): ?ResponseInterface
    {
        $idleTimeoutSeconds = (int) (getenv('AUTH_IDLE_TIMEOUT_SECONDS') !== false ? getenv('AUTH_IDLE_TIMEOUT_SECONDS') : 1800);

        if ($idleTimeoutSeconds === 0) {
            // Idle timeout disabled
            return null;
        }

        $accessTokenJti = $payload['jti'] ?? null;
        $userId = $payload['user_id'] ?? null;

        if ($accessTokenJti === null || $userId === null) {
            // Backward compatibility: skip check for old tokens
            return null;
        }

        try {
            $db = \Config\Database::connect();

            // Fetch last activity timestamp
            $result = $db->table('sessions')
                ->select('last_activity_at')
                ->where('access_token_jti', (string) $accessTokenJti)
                ->where('user_id', (int) $userId)
                ->where('revoked', false)
                ->get();

            if ($result === false) {
                return null; // Session not found - continue (backward compatibility)
            }

            $session = $result->getRowArray();

            if ($session === null) {
                return null; // Session not found - continue (backward compatibility)
            }

            $lastActivityAt = strtotime($session['last_activity_at']);
            $idleSeconds = time() - $lastActivityAt;

            if ($idleSeconds > $idleTimeoutSeconds) {
                // Idle timeout exceeded - revoke session
                $this->sessionManager->revokeSessionByAccessJti((string) $accessTokenJti, (int) $userId);

                $this->logger->warning('Session revoked due to idle timeout', [
                    'domain' => 'Auth',
                    'middleware' => 'JwtAuthenticationMiddleware',
                    'user_id' => $userId,
                    'idle_seconds' => $idleSeconds,
                    'idle_timeout_seconds' => $idleTimeoutSeconds,
                    'security' => 'HIGH',
                ]);

                return $this->unauthorizedResponse(
                    'idle_timeout_exceeded',
                    'Session expired due to inactivity',
                    [
                        'user_id' => $userId,
                        'idle_minutes' => round($idleSeconds / 60),
                    ]
                );
            }

            return null; // Timeout not exceeded
        } catch (\Throwable $e) {
            // Log error but don't fail the request - idle timeout is non-critical
            $this->logger->warning('Idle timeout check failed with exception', [
                'domain' => 'Auth',
                'middleware' => 'JwtAuthenticationMiddleware',
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate device fingerprint from IP and user agent.
     *
     * @param string $ipAddress IP address
     * @param string $userAgent User agent string
     * @return string SHA-256 hash
     */
    private function generateDeviceFingerprint(string $ipAddress, string $userAgent): string
    {
        $data = $ipAddress . '|' . $userAgent;
        return hash('sha256', $data);
    }

    /**
     * Execute middleware after controller.
     *
     * @param RequestInterface $request HTTP request
     * @param ResponseInterface $response HTTP response
     * @param mixed $arguments Optional middleware arguments
     * @return ResponseInterface Unmodified response
     *
     * @phpcsSuppress SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
     */
    public function after(RequestInterface $request, ResponseInterface $response, mixed $arguments = null): ResponseInterface
    {
        return $response;
    }

    /**
     * Check if Authorization header has Bearer token format.
     *
     * @param string $authHeader Authorization header value
     * @return bool True if format is "Bearer {token}"
     */
    private function isBearerTokenFormat(string $authHeader): bool
    {
        return str_starts_with($authHeader, 'Bearer ') && strlen($authHeader) > 7;
    }

    /**
     * Extract token from Bearer authorization header.
     *
     * @param string $authHeader Authorization header value
     * @return string JWT token
     */
    private function extractToken(string $authHeader): string
    {
        return substr($authHeader, 7);
    }

    /**
     * Mask token for logging (show first and last 8 chars).
     *
     * @param string $token Token to mask
     * @return string Masked token
     */
    private function maskToken(string $token): string
    {
        if (strlen($token) <= 16) {
            return '***';
        }

        return substr($token, 0, 8) . '...' . substr($token, -8);
    }

    /**
     * Return 401 Unauthorized response with error details.
     *
     * @param string $errorCode Machine-readable error code
     * @param string $message Human-readable error message
     * @param array<string, mixed> $context Additional context for logging
     * @return ResponseInterface 401 response
     */
    private function unauthorizedResponse(
        string $errorCode,
        string $message,
        array $context = []
    ): ResponseInterface {
        // Log authentication failure for security audit
        $this->logger->warning('JWT authentication failed', [
            'domain' => 'Auth',
            'middleware' => 'JwtAuthenticationMiddleware',
            'error_code' => $errorCode,
            'message' => $message,
            ...$context,
        ]);

        return \Config\Services::response()
            ->setStatusCode(401)
            ->setJSON([
                'error' => $errorCode,
                'message' => $message,
            ]);
    }

    /**
     * Log successful authentication.
     *
     * @param User $user Authenticated user
     * @param RequestInterface $request HTTP request
     */
    private function logAuthenticationSuccess(User $user, RequestInterface $request): void
    {
        $this->logger->info('JWT authentication successful', [
            'domain' => 'Auth',
            'middleware' => 'JwtAuthenticationMiddleware',
            'user_id' => $user->getId(),
            'email' => $user->getEmail()->getValue(),
            'role' => $user->getRole()->value,
            'ip' => $request->getIPAddress(),
            'uri' => $request->getUri()->getPath(),
        ]);
    }

    /**
     * Update session activity timestamp for idle timeout tracking.
     *
     * Extracts access token JTI from payload and updates the last_activity_at
     * timestamp in the sessions table. This enables automatic session expiration
     * after a period of inactivity.
     *
     * @param array<string, mixed> $payload JWT token payload
     */
    private function updateSessionActivity(array $payload): void
    {
        $accessTokenJti = $payload['jti'] ?? null;

        if ($accessTokenJti === null) {
            // No JTI in payload - token created before session tracking was implemented
            // Silently skip session update to maintain backward compatibility
            return;
        }

        try {
            $this->sessionManager->updateLastActivity((string) $accessTokenJti);
        } catch (\Throwable $e) {
            // Log error but don't fail the request - session tracking is non-critical
            $this->logger->warning('Failed to update session activity', [
                'domain' => 'Auth',
                'middleware' => 'JwtAuthenticationMiddleware',
                'exception' => $e->getMessage(),
                'jti' => $accessTokenJti,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Entities\User;
use App\Domain\User\Ports\TokenGeneratorInterface;
use App\Infrastructure\Logging\LoggerFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

final readonly class JwtService implements TokenGeneratorInterface
{
    private string $secretKey;
    private ?string $oldSecretKey;
    private int $accessTokenTtl;
    private int $refreshTokenTtl;
    private LoggerInterface $logger;

    public function __construct()
    {
        $jwtSecret = getenv('JWT_SECRET_KEY');

        // SECURITY: No fallback secret - must be explicitly configured
        if ($jwtSecret === false || $jwtSecret === '') {
            throw new \RuntimeException(
                'SECURITY ERROR: JWT_SECRET_KEY environment variable is not set. ' .
                'Generate a strong secret with: openssl rand -hex 48'
            );
        }

        $this->secretKey = $jwtSecret;

        // Support for old secret during rotation period (7-day overlap recommended)
        $jwtOldSecret = getenv('JWT_SECRET_KEY_OLD');
        $this->oldSecretKey = $jwtOldSecret !== false ? $jwtOldSecret : null;

        // SECURITY: Enforce strong JWT secret in all environments
        if ($this->isWeakSecret($this->secretKey)) {
            throw new \RuntimeException(
                'SECURITY ERROR: Weak or default JWT secret detected. ' .
                'Generate a strong secret with: openssl rand -hex 48'
            );
        }

        $tokenTtl = getenv('AUTH_TOKEN_TTL');
        $this->accessTokenTtl = $tokenTtl !== false ? (int) $tokenTtl : 3600;

        $refreshTtl = getenv('AUTH_REFRESH_TOKEN_TTL');
        $this->refreshTokenTtl = $refreshTtl !== false ? (int) $refreshTtl : 2592000;

        $this->logger = LoggerFactory::create('auth.jwt');
    }

    /**
     * Check if JWT secret is weak or insecure.
     *
     * Weak secrets include:
     * - Default/example values
     * - Publicly known strings
     * - Less than 32 characters (256 bits recommended)
     * - Common patterns or dictionary words
     */
    private function isWeakSecret(string $secret): bool
    {
        // Check minimum length (32 chars = 256 bits)
        if (strlen($secret) < 32) {
            return true;
        }

        // Check for common default values
        $weakSecrets = [
            'your-secret-key-change-in-production',
            'your-secret-key-here-change-in-production',
            'secret',
            'change-me',
            'jwt-secret',
            'default-secret',
        ];

        foreach ($weakSecrets as $weakSecret) {
            if (stripos($secret, $weakSecret) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate cryptographically secure unique token ID (jti).
     *
     * SECURITY: The jti (JWT ID) claim is critical for:
     * - Token blacklisting/revocation (enables logout, password reset invalidation)
     * - Preventing replay attacks (each token is uniquely identifiable)
     * - Audit trails (track specific token usage)
     * - Compliance requirements (GDPR, PCI-DSS require token tracking)
     *
     * Without jti, we cannot reliably blacklist individual tokens without
     * invalidating ALL tokens for a user (poor UX and security risk).
     *
     * @return string 32-character hexadecimal unique ID
     */
    private function generateUniqueTokenId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function generateAccessToken(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => 'cqrs-auth',
            'iat' => $now,
            'exp' => $now + $this->accessTokenTtl,
            'jti' => $this->generateUniqueTokenId(),
            'user_id' => $user->getId(),
            'role' => $user->getRole()->value,
            'type' => 'access',
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    public function generateRefreshToken(User $user): string
    {
        $now = time();
        $payload = [
            'iss' => 'cqrs-auth',
            'iat' => $now,
            'exp' => $now + $this->refreshTokenTtl,
            'jti' => $this->generateUniqueTokenId(),
            'user_id' => $user->getId(),
            'type' => 'refresh',
        ];

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * Validate and decode a JWT token with secret rotation support.
     *
     * SECURITY NOTE: JWT Secret Rotation Procedure
     * ============================================
     * This method supports graceful secret rotation with a 7-day overlap period:
     *
     * Day 0: Generate new secret: openssl rand -hex 48
     * Day 0: Set JWT_SECRET_KEY_OLD=<current secret>
     * Day 0: Set JWT_SECRET_KEY=<new secret>
     * Day 0-7: Both secrets valid (tokens issued with new, validated with both)
     * Day 7: Remove JWT_SECRET_KEY_OLD (all old tokens expired or refreshed)
     *
     * During overlap period:
     * - New tokens signed with current secret
     * - Old tokens validated with old secret (fallback)
     * - Warning logged when old secret used (monitoring/alerting)
     *
     * @param string $token JWT token
     * @param string|null $expectedType Expected token type ('access' or 'refresh')
     * @return array<string, mixed> Decoded token payload
     * @throws \Firebase\JWT\SignatureInvalidException When signature invalid
     * @throws \Firebase\JWT\ExpiredException When token expired
     * @throws \UnexpectedValueException When token malformed or type mismatches
     */
    public function validateToken(string $token, ?string $expectedType = null): array
    {
        try {
            // Try current secret first (normal case)
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            $payload = (array) $decoded;
        } catch (\Exception $currentSecretException) {
            // Try old secret if available (rotation period fallback)
            if ($this->oldSecretKey === null) {
                // No old secret available, throw original exception
                throw $currentSecretException;
            }

            try {
                $decoded = JWT::decode($token, new Key($this->oldSecretKey, 'HS256'));
                $payload = (array) $decoded;

                // Log warning for monitoring/alerting
                $this->logger->warning('Token validated with old JWT secret - token should be refreshed', [
                    'domain' => 'Auth',
                    'token_type' => $payload['type'] ?? 'unknown',
                    'user_id' => $payload['user_id'] ?? null,
                    'token_jti' => $payload['jti'] ?? null,
                    'token_issued_at' => isset($payload['iat']) ? date('Y-m-d H:i:s', (int) $payload['iat']) : null,
                    'days_since_issue' => isset($payload['iat']) ? (int) ((time() - (int) $payload['iat']) / 86400) : null,
                ]);
            } catch (\Exception $oldSecretException) {
                // Both secrets failed, throw original exception
                throw $currentSecretException;
            }
        }

        // SECURITY: Validate token type to prevent misuse
        // Prevents using refresh tokens as access tokens and vice versa
        if ($expectedType !== null) {
            $actualType = $payload['type'] ?? null;

            if ($actualType === null) {
                throw new \UnexpectedValueException(
                    'Token is missing type claim. Expected type: ' . $expectedType
                );
            }

            if ($actualType !== $expectedType) {
                throw new \UnexpectedValueException(
                    'Invalid token type. Expected: ' . $expectedType . ', Got: ' . $actualType
                );
            }
        }

        return $payload;
    }

    /**
     * Get token payload without validation.
     *
     * @param string $token JWT token
     * @return array<string, mixed> Token payload
     */
    public function getTokenPayload(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Invalid JWT format');
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payload === false) {
            throw new \InvalidArgumentException('Invalid JWT payload');
        }
        return json_decode($payload, true) ?? [];
    }
}

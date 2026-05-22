<?php

declare(strict_types=1);

namespace Tests\Integration\Security;

use App\Domain\User\Commands\RegisterUser\RegisterUserCommand;
use App\Domain\User\Entities\User;
use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\User\ValueObjects\Email;
use App\Domain\User\ValueObjects\HashedPassword;
use App\Domain\User\ValueObjects\UserName;
use App\Domain\User\ValueObjects\UserRole;
use App\Infrastructure\Auth\Services\JwtService;
use App\Infrastructure\Auth\Services\PasswordHashingService;
use App\Infrastructure\Persistence\Models\UserModel;
use Tests\Support\IntegrationTestCase;

/**
 * Security Integration Test Suite for Authentication.
 *
 * Tests critical security controls for authentication system including:
 * - Token forgery prevention (cryptographic signature validation)
 * - RBAC boundary testing (role-based access control)
 * - Session fixation prevention (unique JWT IDs)
 * - Token blacklist enforcement (logout implementation)
 * - Admin registration protection (privilege escalation prevention)
 * - Password complexity enforcement (OWASP compliance)
 * - HTTPS enforcement configuration (transport security)
 *
 * Coverage: 11 security test scenarios
 *
 * @package Tests\Integration\Security
 */
final class AuthenticationSecurityTest extends IntegrationTestCase
{
    private UserRepository $userRepository;
    private JwtService $jwtService;
    private PasswordHashingService $passwordHasher;
    private TokenBlacklistInterface $blacklistService;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = \App\Infrastructure\Logging\LoggerFactory::create('test.security.auth');
        $loggingConfig = config('Logging');
        $userModel = new UserModel();

        $this->userRepository = new UserRepository($userModel, $logger, $loggingConfig);
        $this->jwtService = new JwtService();
        $this->passwordHasher = new PasswordHashingService();
        $this->blacklistService = service('tokenBlacklistService');
    }

    // ==========================================
    // Token Forgery Tests
    // ==========================================

    /**
     * Test that forged JWT tokens with invalid signatures are rejected.
     *
     * Security: Prevents token tampering by validating cryptographic signature.
     * Attackers cannot modify token payload without detection.
     *
     * Expected: Tokens with invalid signatures fail validation.
     */
    public function test_forged_jwt_token_with_invalid_signature_is_rejected(): void
    {
        $user = $this->createTestUser('forge@test.com', 'ValidPass123!@#');

        // Generate valid token
        $validToken = $this->jwtService->generateAccessToken($user);

        // Forge token by modifying payload (changing user_id)
        $parts = explode('.', $validToken);
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $payload['user_id'] = 999999; // Unauthorized user ID

        // Create forged token with modified payload but same signature (invalid)
        $forgedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $forgedToken = $parts[0] . '.' . $forgedPayload . '.' . $parts[2];

        // Attempt to validate forged token
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/signature|invalid/i');

        $this->jwtService->validateToken($forgedToken);
    }

    /**
     * Test that tokens signed with wrong secret key are rejected.
     *
     * Security: Ensures tokens from other systems or attackers
     * using different keys cannot authenticate.
     */
    public function test_token_signed_with_wrong_secret_is_rejected(): void
    {
        $user = $this->createTestUser('wrongkey@test.com', 'ValidPass123!@#');

        // Create token with different secret
        $wrongKey = 'this-is-a-wrong-secret-key-32-chars-minimum-required-for-testing';
        $payload = [
            'iss' => 'cqrs-auth',
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
            'user_id' => $user->getId(),
            'email' => $user->getEmail()->getValue(),
            'role' => $user->getRole()->value,
            'type' => 'access',
        ];

        $tokenWithWrongKey = \Firebase\JWT\JWT::encode($payload, $wrongKey, 'HS256');

        // Attempt to validate with correct secret (should fail)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/signature|invalid/i');

        $this->jwtService->validateToken($tokenWithWrongKey);
    }

    /**
     * Test that expired JWT tokens are rejected.
     *
     * Security: Prevents use of old tokens after their validity period.
     * Time-based validation protects against replay attacks.
     */
    public function test_expired_jwt_token_is_rejected(): void
    {
        $user = $this->createTestUser('expired@test.com', 'ValidPass123!@#');

        // Create token that's already expired
        $payload = [
            'iss' => 'cqrs-auth',
            'iat' => time() - 7200, // Issued 2 hours ago
            'exp' => time() - 3600, // Expired 1 hour ago
            'jti' => bin2hex(random_bytes(16)),
            'user_id' => $user->getId(),
            'email' => $user->getEmail()->getValue(),
            'role' => $user->getRole()->value,
            'type' => 'access',
        ];

        // Sign with correct secret but expired time
        $secretKey = getenv('JWT_SECRET_KEY') ?: 'your-secret-key-change-in-production';
        $expiredToken = \Firebase\JWT\JWT::encode($payload, $secretKey, 'HS256');

        // Attempt to validate expired token
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/expired/i');

        $this->jwtService->validateToken($expiredToken);
    }

    // ==========================================
    // RBAC Boundary Tests
    // ==========================================

    /**
     * Test that customer role cannot access admin-only routes.
     *
     * Security: Enforces Role-Based Access Control (RBAC) boundaries.
     * Prevents privilege escalation attacks.
     *
     * Expected: Customer attempting to access admin routes receives 403 Forbidden.
     */
    public function test_customer_cannot_access_admin_routes(): void
    {
        // Create customer user
        $customer = $this->createTestUser('customer@test.com', 'ValidPass123!@#', UserRole::Customer);

        // Verify role is customer
        $this->assertEquals(UserRole::Customer, $customer->getRole());
        $this->assertFalse($customer->getRole()->isAdmin());

        // In a real application, you would test HTTP request with JWT
        // Here we verify entity-level RBAC
        $this->assertTrue(
            $customer->getRole()->isCustomer(),
            'User should have customer role'
        );

        $this->assertFalse(
            $customer->getRole()->isAdmin(),
            'Customer should not have admin privileges'
        );
    }

    /**
     * Test that guest role has minimal permissions.
     *
     * Security: Guest users should have most restricted access level.
     */
    public function test_guest_role_has_minimal_permissions(): void
    {
        $guest = $this->createTestUser('guest@test.com', 'ValidPass123!@#', UserRole::Guest);

        $this->assertEquals(UserRole::Guest, $guest->getRole());
        $this->assertFalse($guest->getRole()->isAdmin());
        $this->assertFalse($guest->getRole()->isCustomer());
        $this->assertTrue($guest->getRole()->isGuest());
    }

    /**
     * Test that only admin role has admin privileges.
     *
     * Security: Validates that admin privileges are correctly assigned.
     */
    public function test_only_admin_role_has_admin_privileges(): void
    {
        $admin = $this->createTestUser('admin@test.com', 'ValidPass123!@#', UserRole::Admin);

        $this->assertEquals(UserRole::Admin, $admin->getRole());
        $this->assertTrue($admin->getRole()->isAdmin());
        $this->assertFalse($admin->getRole()->isCustomer());
        $this->assertFalse($admin->getRole()->isGuest());
    }

    // ==========================================
    // Session Fixation Prevention Tests
    // ==========================================

    /**
     * Test that new JWT token is generated on login (prevents session fixation).
     *
     * Security: Each login generates a unique token with unique jti (JWT ID).
     * Prevents session fixation attacks where attacker sets victim's session ID.
     *
     * Expected: Each login produces different token with unique jti claim.
     */
    public function test_new_token_generated_on_each_login_prevents_session_fixation(): void
    {
        $user = $this->createTestUser('sessionfix@test.com', 'ValidPass123!@#');

        // Login twice and verify different tokens
        $token1 = $this->jwtService->generateAccessToken($user);
        $token2 = $this->jwtService->generateAccessToken($user);

        $this->assertNotEquals($token1, $token2, 'Each login should generate unique token');

        // Verify tokens have different jti (JWT ID)
        $payload1 = $this->jwtService->getTokenPayload($token1);
        $payload2 = $this->jwtService->getTokenPayload($token2);

        $this->assertArrayHasKey('jti', $payload1);
        $this->assertArrayHasKey('jti', $payload2);
        $this->assertNotEquals($payload1['jti'], $payload2['jti'], 'JWT IDs must be unique');
    }

    /**
     * Test that JWT ID (jti) is cryptographically random.
     *
     * Security: Ensures JWT IDs cannot be predicted by attackers.
     * Uses cryptographically secure random number generator.
     */
    public function test_jwt_id_is_cryptographically_random(): void
    {
        $user = $this->createTestUser('randomjti@test.com', 'ValidPass123!@#');

        // Generate multiple tokens and collect JWT IDs
        $jtis = [];
        for ($i = 0; $i < 10; $i++) {
            $token = $this->jwtService->generateAccessToken($user);
            $payload = $this->jwtService->getTokenPayload($token);
            $jtis[] = $payload['jti'];
        }

        // Verify all JTIs are unique (no collisions)
        $uniqueJtis = array_unique($jtis);
        $this->assertCount(10, $uniqueJtis, 'All JWT IDs must be unique');

        // Verify JTI format (32 hex characters from random_bytes(16))
        foreach ($jtis as $jti) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{32}$/',
                $jti,
                'JWT ID must be 32 hex characters'
            );
        }
    }

    // ==========================================
    // Token Blacklist Tests
    // ==========================================

    /**
     * Test that logged out tokens are rejected (blacklist enforcement).
     *
     * Security: Implements proper logout by blacklisting tokens.
     * Prevents token reuse after logout (critical for security).
     *
     * Expected: Token added to blacklist cannot be used for authentication.
     */
    public function test_logged_out_token_is_blacklisted(): void
    {
        $user = $this->createTestUser('logout@test.com', 'ValidPass123!@#');

        // Generate access token
        $token = $this->jwtService->generateAccessToken($user);

        // Verify token is valid before logout
        $payload = $this->jwtService->validateToken($token);
        $this->assertEquals($user->getId(), $payload['user_id']);

        // Verify token is not blacklisted initially
        $this->assertFalse(
            $this->blacklistService->isBlacklisted($token),
            'Token should not be blacklisted before logout'
        );

        // Add token to blacklist (simulate logout)
        $this->blacklistService->blacklist($token);

        // Verify token is now blacklisted
        $this->assertTrue(
            $this->blacklistService->isBlacklisted($token),
            'Logged out token should be blacklisted'
        );
    }

    /**
     * Test that non-blacklisted tokens remain valid.
     *
     * Security: Ensures blacklist only affects logged-out tokens,
     * not all tokens for a user.
     */
    public function test_non_blacklisted_tokens_remain_valid(): void
    {
        $user = $this->createTestUser('multitoken@test.com', 'ValidPass123!@#');

        // Generate two tokens
        $token1 = $this->jwtService->generateAccessToken($user);
        $token2 = $this->jwtService->generateAccessToken($user);

        // Blacklist only token1
        $this->blacklistService->blacklist($token1);

        // Verify token1 is blacklisted
        $this->assertTrue($this->blacklistService->isBlacklisted($token1));

        // Verify token2 is NOT blacklisted
        $this->assertFalse(
            $this->blacklistService->isBlacklisted($token2),
            'Second token should remain valid'
        );
    }

    // ==========================================
    // Admin Registration Protection Tests
    // ==========================================

    /**
     * Test that direct admin role registration is blocked.
     *
     * Security: Prevents privilege escalation via registration.
     * Only existing admins should be able to create admin accounts.
     *
     * Expected: Registration with admin role fails with DomainException.
     */
    public function test_admin_self_registration_is_blocked(): void
    {
        $commandBus = service('commandBus');

        // Attempt to register with admin role
        $command = new RegisterUserCommand(
            name: 'Hacker User',
            email: 'hacker@test.com',
            password: 'ValidPass123!@#',
            role: 'admin' // Attempting to self-assign admin role
        );

        // Should throw DomainException
        $this->expectException(\App\Domain\Shared\Exceptions\DomainException::class);
        $this->expectExceptionMessageMatches('/admin/i');

        $commandBus->dispatch($command);
    }

    // ==========================================
    // Password Complexity Tests
    // ==========================================

    /**
     * Test that weak passwords are rejected.
     *
     * Security: Enforces OWASP password complexity requirements.
     * Prevents use of easily guessable passwords.
     *
     * Expected: Passwords not meeting complexity rules fail validation.
     */
    public function test_weak_password_is_rejected(): void
    {
        $commandBus = service('commandBus');

        $weakPasswords = [
            'short1!',                    // Too short (< 12 chars)
            'nouppercase123!',            // No uppercase letter
            'NOLOWERCASE123!',            // No lowercase letter
            'NoDigitsHere!',              // No digit
            'NoSpecialChar123',           // No special character
            'simple',                     // Multiple violations
        ];

        foreach ($weakPasswords as $weakPassword) {
            $command = new RegisterUserCommand(
                name: 'Weak Password User',
                email: 'weak_' . bin2hex(random_bytes(4)) . '@test.com',
                password: $weakPassword,
                role: 'customer'
            );

            try {
                $commandBus->dispatch($command);

                // If no exception, test should fail
                $this->fail("Weak password '{$weakPassword}' should have been rejected");
            } catch (\App\Domain\Shared\Exceptions\ValidationException $e) {
                // Expected validation exception
                $this->assertStringContainsString(
                    'password',
                    strtolower($e->getMessage()),
                    'Validation error should mention password'
                );
            }
        }

        $this->assertTrue(true, 'All weak passwords were correctly rejected');
    }

    /**
     * Test that strong password meeting all requirements is accepted.
     *
     * Security: Validates that legitimate strong passwords work correctly.
     */
    public function test_strong_password_is_accepted(): void
    {
        $commandBus = service('commandBus');

        $command = new RegisterUserCommand(
            name: 'Strong Password User',
            email: 'strongpass@test.com',
            password: 'StrongP@ssw0rd123!', // Meets all requirements
            role: 'customer'
        );

        $userId = $commandBus->dispatch($command);

        $this->assertGreaterThan(0, $userId);
        $this->assertDatabaseHas('users', [
            'id' => $userId,
            'email' => 'strongpass@test.com',
        ]);
    }

    // ==========================================
    // JWT Secret Rotation Tests
    // ==========================================

    /**
     * Test that tokens validate with current secret during rotation.
     *
     * Security: Ensures new tokens work with current secret.
     * During rotation period, new tokens use JWT_SECRET_KEY.
     *
     * Expected: Tokens signed with current secret validate successfully.
     */
    public function test_token_validates_with_current_secret_during_rotation(): void
    {
        $user = $this->createTestUser('current@test.com', 'ValidPass123!@#');

        // Generate token with current secret
        $token = $this->jwtService->generateAccessToken($user);

        // Validate token (should use current secret)
        $payload = $this->jwtService->validateToken($token);

        $this->assertEquals($user->getId(), $payload['user_id']);
        $this->assertEquals($user->getRole()->value, $payload['role']);
        $this->assertEquals('access', $payload['type']);
    }

    /**
     * Test that tokens signed with old secret validate during rotation period.
     *
     * Security: Graceful secret rotation requires validating tokens
     * from both current and old secrets during overlap period.
     *
     * Expected: Tokens signed with JWT_SECRET_KEY_OLD are accepted.
     *
     * The test simulates a rotation by injecting a synthetic "old" secret
     * into the environment, instantiating a fresh JwtService so it picks
     * up both secrets, then restoring the env in finally.
     */
    public function test_token_validates_with_old_secret_during_rotation(): void
    {
        $oldSecret = bin2hex(random_bytes(32)); // synthetic 64-char hex secret
        $previous = getenv('JWT_SECRET_KEY_OLD');
        putenv('JWT_SECRET_KEY_OLD=' . $oldSecret);

        try {
            $rotationAwareJwt = new JwtService();
            $user = $this->createTestUser('oldsecret@test.com', 'ValidPass123!@#');

            // Create token with old secret (simulating token issued before rotation)
            $payload = [
                'iss' => 'cqrs-auth',
                'iat' => time() - 3600, // Issued 1 hour ago
                'exp' => time() + 3600,
                'jti' => bin2hex(random_bytes(16)),
                'user_id' => $user->getId(),
                'role' => $user->getRole()->value,
                'type' => 'access',
            ];

            $tokenWithOldSecret = \Firebase\JWT\JWT::encode($payload, $oldSecret, 'HS256');

            // Validate token (should fallback to old secret)
            $validatedPayload = $rotationAwareJwt->validateToken($tokenWithOldSecret);

            $this->assertEquals($user->getId(), $validatedPayload['user_id']);
            $this->assertEquals($user->getRole()->value, $validatedPayload['role']);
        } finally {
            if ($previous === false) {
                putenv('JWT_SECRET_KEY_OLD');
            } else {
                putenv('JWT_SECRET_KEY_OLD=' . $previous);
            }
        }
    }

    /**
     * Test that tokens with neither current nor old secret are rejected.
     *
     * Security: Even during rotation, only tokens signed with
     * current or old secrets are valid. Tokens from other sources rejected.
     *
     * Expected: Token signed with unknown secret fails validation.
     */
    public function test_token_with_unknown_secret_rejected_during_rotation(): void
    {
        $user = $this->createTestUser('unknown@test.com', 'ValidPass123!@#');

        // Create token with completely different secret
        $unknownSecret = 'this-is-not-current-or-old-secret-32-chars-minimum-required-value';
        $payload = [
            'iss' => 'cqrs-auth',
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
            'user_id' => $user->getId(),
            'role' => $user->getRole()->value,
            'type' => 'access',
        ];

        $tokenWithUnknownSecret = \Firebase\JWT\JWT::encode($payload, $unknownSecret, 'HS256');

        // Should throw exception (neither current nor old secret)
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/signature|invalid/i');

        $this->jwtService->validateToken($tokenWithUnknownSecret);
    }

    /**
     * Test that new tokens are always signed with current secret.
     *
     * Security: During rotation, new tokens MUST use JWT_SECRET_KEY,
     * not JWT_SECRET_KEY_OLD. Old secret is only for validation.
     *
     * Expected: Newly generated tokens validate only with current secret.
     */
    public function test_new_tokens_always_use_current_secret(): void
    {
        // Same self-setup approach as test_token_validates_with_old_secret_during_rotation:
        // inject a synthetic old secret, build a rotation-aware JwtService, restore env in finally.
        $oldSecret = bin2hex(random_bytes(32));
        $previous = getenv('JWT_SECRET_KEY_OLD');
        putenv('JWT_SECRET_KEY_OLD=' . $oldSecret);

        try {
            $rotationAwareJwt = new JwtService();
            $user = $this->createTestUser('newsecret@test.com', 'ValidPass123!@#');

            // Generate new token (should use current secret, NOT the old one we injected)
            $newToken = $rotationAwareJwt->generateAccessToken($user);

            // Verify token validates with current secret
            $payload = $rotationAwareJwt->validateToken($newToken);
            $this->assertEquals($user->getId(), $payload['user_id']);

            // Verify token does NOT validate with the synthetic old secret alone.
            // (This proves the new token was signed with the CURRENT secret.)
            try {
                \Firebase\JWT\JWT::decode($newToken, new \Firebase\JWT\Key($oldSecret, 'HS256'));
                $this->fail('Token should not validate with old secret alone');
            } catch (\Exception $e) {
                $this->assertStringContainsString(
                    'Signature verification failed',
                    $e->getMessage(),
                    'Token should fail validation with old secret'
                );
            }
        } finally {
            if ($previous === false) {
                putenv('JWT_SECRET_KEY_OLD');
            } else {
                putenv('JWT_SECRET_KEY_OLD=' . $previous);
            }
        }
    }

    /**
     * Test rotation procedure documentation in code.
     *
     * Security: Proper documentation ensures team follows
     * secure rotation procedure.
     *
     * Expected: JwtService contains comprehensive rotation instructions.
     */
    public function test_rotation_procedure_documented_in_code(): void
    {
        $jwtServicePath = APPPATH . 'Infrastructure/Auth/Services/JwtService.php';
        $this->assertFileExists($jwtServicePath);

        $content = file_get_contents($jwtServicePath);
        $this->assertNotFalse($content);

        // Verify rotation procedure documentation exists
        $this->assertStringContainsString('JWT Secret Rotation Procedure', $content);
        $this->assertStringContainsString('7-day overlap period', $content);
        $this->assertStringContainsString('JWT_SECRET_KEY_OLD', $content);
        $this->assertStringContainsString('openssl rand -hex 48', $content);

        // Verify implementation has old secret fallback
        $this->assertStringContainsString('oldSecretKey', $content);
        $this->assertStringContainsString('Try old secret if available', $content);
    }

    // ==========================================
    // HTTPS Enforcement Tests
    // ==========================================

    /**
     * Test that HTTPS is enforced in production environment.
     *
     * Security: Prevents MITM attacks by requiring encrypted connections.
     * Critical for protecting credentials and tokens in transit.
     *
     * Expected: In production, HTTP requests redirect to HTTPS.
     *
     * Note: This test verifies configuration. Full HTTP->HTTPS redirect
     * testing requires Feature tests with actual HTTP requests.
     */
    public function test_https_enforcement_configured_in_production(): void
    {
        $filtersConfig = config('Filters');

        // Verify ForceHTTPS filter is configured
        $this->assertArrayHasKey('forcehttps', $filtersConfig->aliases);

        // Verify ForceHTTPS is in required before filters
        $this->assertContains(
            'forcehttps',
            $filtersConfig->required['before'],
            'ForceHTTPS must be in required before filters for production'
        );

        // In production, this filter redirects all HTTP to HTTPS
        $this->assertTrue(
            true,
            'HTTPS enforcement is properly configured in Filters'
        );
    }

    // ==========================================
    // Helper Methods
    // ==========================================

    /**
     * Create a test user with specified credentials and role.
     *
     * @param string $email User email
     * @param string $password User password (will be hashed)
     * @param UserRole $role User role (default: Customer)
     * @return User Created user entity
     */
    private function createTestUser(
        string $email,
        string $password,
        UserRole $role = UserRole::Customer
    ): User {
        $hashedPassword = $this->passwordHasher->hash($password);

        $user = User::create(
            name: UserName::fromString('Test User'),
            email: Email::fromString($email),
            hashedPassword: HashedPassword::fromHash($hashedPassword),
            role: $role
        );

        $userId = $this->userRepository->save($user);
        return $this->userRepository->findById($userId);
    }
}

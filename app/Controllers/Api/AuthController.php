<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\Commands\RegisterUser\RegisterUserCommand;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserCommand;
use App\Infrastructure\Auth\Commands\LogoutUser\LogoutUserCommand;
use App\Infrastructure\Auth\Commands\RefreshToken\RefreshTokenCommand;
use App\Infrastructure\Auth\Commands\RequestPasswordReset\RequestPasswordResetCommand;
use App\Infrastructure\Auth\Commands\ResetPassword\ResetPasswordCommand;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;

/**
 * REST API authentication controller.
 * Returns JSON responses with JWT tokens.
 */
final class AuthController extends ResourceController
{
    /**
     * Response format for all API endpoints.
     *
     * @var 'html'|'json'|'xml'|null
     */
    protected $format = 'json';

    /**
     * register.
     *
     * @return ResponseInterface
     */
    public function register(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $command = new RegisterUserCommand(
                name: $data['name'] ?? '',
                email: $data['email'] ?? '',
                password: $data['password'] ?? '',
                role: $data['role'] ?? 'customer'
            );

            $userId = $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'User registered successfully',
                'data' => ['user_id' => $userId],
            ], 201);
        } catch (ValidationException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->failServerError('Registration failed');
        }
    }

    /**
     * login.
     *
     * @return ResponseInterface
     */
    public function login(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $command = new LoginUserCommand(
                email: $data['email'] ?? '',
                password: $data['password'] ?? '',
                ipAddress: $this->request->getIPAddress(),
                userAgent: $this->request->getUserAgent()->getAgentString()
            );

            $result = $commandBus->dispatch($command);

            if ($result->isSuccess()) {
                return $this->respond([
                    'success' => true,
                    'message' => 'Login successful',
                    'data' => [
                        'access_token' => $result->accessToken->getValue(),
                        'refresh_token' => $result->refreshToken->getValue(),
                        'expires_in' => $result->expiresIn,
                        'token_type' => 'Bearer',
                        'user' => [
                            'id' => $result->user->getId(),
                            'email' => $result->user->getEmail()->getValue(),
                            'role' => $result->user->getRole()->value,
                        ],
                    ],
                ]);
            }

            return $this->failUnauthorized('Invalid credentials');
        } catch (\Throwable $e) {
            return $this->failServerError('Login failed');
        }
    }

    /**
     * logout.
     *
     * @return ResponseInterface
     */
    public function logout(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            // Get access token from Authorization header
            $token = $this->request->getHeaderLine('Authorization');
            $token = str_replace('Bearer ', '', $token);

            if ($token === '') {
                return $this->failUnauthorized('Token required');
            }

            // Get refresh token from request body (optional but recommended)
            $data = $this->request->getJSON(true);
            assert(is_array($data) || $data === null);
            $refreshToken = $data['refresh_token'] ?? null;

            // Get user ID from authenticated request (set by JwtAuthenticationMiddleware)
            // PHPStan: Dynamic property set by middleware
            /** @phpstan-ignore-next-line */
            $user = $this->request->user ?? null;
            $userId = $user?->getId();

            // SECURITY: Complete logout - blacklist both tokens and revoke session (CR-2.1)
            $command = new LogoutUserCommand(
                token: $token,
                refreshToken: $refreshToken,
                userId: $userId
            );
            $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Logout failed');
        }
    }

    /**
     * refresh.
     *
     * @return ResponseInterface
     */
    public function refresh(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $refreshToken = $data['refresh_token'] ?? '';

            if ($refreshToken === '') {
                return $this->fail('Refresh token required', 400);
            }

            $command = new RefreshTokenCommand($refreshToken);
            $result = $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'data' => $result->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->failUnauthorized('Invalid refresh token');
        }
    }

    /**
     * requestPasswordReset.
     *
     * @return ResponseInterface
     */
    public function requestPasswordReset(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $email = $data['email'] ?? '';

            if ($email === '') {
                return $this->fail('Email required', 400);
            }

            $command = new RequestPasswordResetCommand($email);
            $commandBus->dispatch($command);

            // Always return success (prevent user enumeration)
            return $this->respond([
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent.',
            ]);
        } catch (\Throwable $e) {
            // Silent failure - don't reveal errors
            return $this->respond([
                'success' => true,
                'message' => 'If the email exists, a password reset link has been sent.',
            ]);
        }
    }

    /**
     * resetPassword.
     *
     * @return ResponseInterface
     */
    public function resetPassword(): ResponseInterface
    {
        $commandBus = service('commandBus');
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $token = $data['token'] ?? '';
            $newPassword = $data['new_password'] ?? '';

            if ($token === '' || $newPassword === '') {
                return $this->fail('Token and new password required', 400);
            }

            $command = new ResetPasswordCommand($token, $newPassword);
            $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'Password reset successfully. Please login with your new password.',
            ]);
        } catch (\Throwable $e) {
            return $this->fail('Invalid or expired reset token', 400);
        }
    }

    /**
     * me.
     *
     * @return ResponseInterface
     */
    public function me(): ResponseInterface
    {
        assert($this->request instanceof IncomingRequest);

        // PHPStan: Dynamic property set by JwtAuthenticationMiddleware
        /** @phpstan-ignore-next-line */
        $user = $this->request->user ?? null;

        if ($user === null) {
            return $this->failUnauthorized('Unauthorized');
        }

        return $this->respond([
            'success' => true,
            'data' => [
                'id' => $user->getId(),
                'email' => $user->getEmail()->getValue(),
                'role' => $user->getRole()->value,
                'status' => $user->getStatus()->value,
            ],
        ]);
    }

    /**
     * List active sessions for authenticated user.
     *
     * @return ResponseInterface
     */
    public function listSessions(): ResponseInterface
    {
        assert($this->request instanceof IncomingRequest);

        // PHPStan: Dynamic property set by JwtAuthenticationMiddleware
        /** @phpstan-ignore-next-line */
        $user = $this->request->user ?? null;

        if ($user === null) {
            return $this->failUnauthorized('Unauthorized');
        }

        try {
            $sessionManager = service('sessionManagementService');
            $sessions = $sessionManager->getActiveSessions($user->getId());

            return $this->respond([
                'success' => true,
                'data' => ['sessions' => $sessions],
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to retrieve sessions');
        }
    }

    /**
     * Revoke specific session for authenticated user.
     *
     * @param int $sessionId Session ID to revoke
     * @return ResponseInterface
     */
    public function revokeSession(int $sessionId): ResponseInterface
    {
        assert($this->request instanceof IncomingRequest);

        // PHPStan: Dynamic property set by JwtAuthenticationMiddleware
        /** @phpstan-ignore-next-line */
        $user = $this->request->user ?? null;

        if ($user === null) {
            return $this->failUnauthorized('Unauthorized');
        }

        try {
            $sessionManager = service('sessionManagementService');
            $sessionManager->revokeSession($sessionId, $user->getId());

            return $this->respond([
                'success' => true,
                'message' => 'Session revoked successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to revoke session');
        }
    }

    /**
     * Revoke all sessions for authenticated user (force logout from all devices).
     *
     * @return ResponseInterface
     */
    public function revokeAllSessions(): ResponseInterface
    {
        assert($this->request instanceof IncomingRequest);

        // PHPStan: Dynamic property set by JwtAuthenticationMiddleware
        /** @phpstan-ignore-next-line */
        $user = $this->request->user ?? null;

        if ($user === null) {
            return $this->failUnauthorized('Unauthorized');
        }

        try {
            $sessionManager = service('sessionManagementService');
            $sessionManager->revokeAllUserSessions($user->getId());

            return $this->respond([
                'success' => true,
                'message' => 'All sessions revoked successfully. You have been logged out from all devices.',
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to revoke sessions');
        }
    }
}

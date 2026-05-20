<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordCommand;
use App\Domain\User\Commands\CreateUser\CreateUserCommand;
use App\Domain\User\Commands\DeleteUser\DeleteUserCommand;
use App\Domain\User\Commands\UpdateUser\UpdateUserCommand;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersQuery;
use App\Domain\User\Queries\GetUserById\GetUserByIdQuery;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\RESTful\ResourceController;
use Config\Services;

/**
 * User API Controller - REST interface for user management.
 *
 * Responsibilities:
 * - Handle HTTP requests/responses for API endpoints
 * - Validate incoming request data
 * - Dispatch commands and queries via buses
 * - Return JSON responses with proper HTTP status codes
 * - Handle exceptions and convert to appropriate API responses
 *
 * Security:
 * - NEVER include password_hash in API responses
 * - Validate all input before creating commands
 * - Return appropriate HTTP status codes (200, 201, 404, 422, 500)
 *
 * CQRS Pattern:
 * - Write operations use CommandBus (create, update, delete, resetPassword)
 * - Read operations use QueryBus (index, show)
 * - Thin controller - no business logic, only HTTP concerns
 *
 * @package App\Controllers\Api
 */
final class UserController extends ResourceController
{
    /**
     * Response format for all API endpoints.
     *
     * @var 'html'|'json'|'xml'|null
     */
    protected $format = 'json';

    /**
     * List users with pagination.
     *
     * GET /api/v1/users
     *
     * Query Parameters:
     * - page: Page number (default: 1)
     * - perPage: Items per page (default: 20)
     * - search: Search term for email (optional)
     * - includeInactive: Include deleted users (default: false)
     *
     * Response: 200 OK
     * {
     *   "success": true,
     *   "data": [...users...],
     *   "pagination": {
     *     "total": 100,
     *     "page": 1,
     *     "perPage": 20,
     *     "totalPages": 5
     *   }
     * }
     */
    public function index(): ResponseInterface
    {
        $queryBus = Services::queryBus();
        assert($this->request instanceof IncomingRequest);

        try {
            $pageParam = $this->request->getGet('page');
            $page = is_numeric($pageParam) ? (int) $pageParam : 1;

            $perPageParam = $this->request->getGet('perPage');
            $perPage = is_numeric($perPageParam) ? (int) $perPageParam : 20;

            $searchParam = $this->request->getGet('search');
            $search = is_string($searchParam) ? $searchParam : '';

            $includeInactiveParam = $this->request->getGet('includeInactive');
            $includeInactive = filter_var($includeInactiveParam, FILTER_VALIDATE_BOOLEAN);

            $query = new GetAllUsersQuery(
                page: $page,
                perPage: $perPage,
                includeInactive: $includeInactive,
                searchTerm: $search
            );

            $result = $queryBus->ask($query);

            // Transform DTOs to API response format
            $users = array_map(
                static fn ($user) => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'failed_login_attempts' => $user->failedLoginAttempts,
                    'locked_until' => $user->lockedUntil,
                    'created_at' => $user->createdAt,
                    'updated_at' => $user->updatedAt,
                ],
                $result['data']
            );

            return $this->respond([
                'success' => true,
                'data' => $users,
                'pagination' => [
                    'total' => $result['total'],
                    'page' => $result['page'],
                    'perPage' => $result['perPage'],
                    'lastPage' => $result['lastPage'],
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to retrieve users: ' . $e->getMessage());
        }
    }

    /**
     * Get single user by ID.
     *
     * GET /api/v1/users/{id}
     *
     * @param mixed $id User ID
     *
     * Response: 200 OK
     * {
     *   "success": true,
     *   "data": {...user...}
     * }
     *
     * Response: 404 Not Found
     * {
     *   "success": false,
     *   "message": "User not found"
     * }
     */
    public function show($id = null): ResponseInterface
    {
        $queryBus = Services::queryBus();

        try {
            $query = new GetUserByIdQuery(id: (int) $id);
            $user = $queryBus->ask($query);

            if ($user === null) {
                return $this->failNotFound('User not found');
            }

            return $this->respond([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'failed_login_attempts' => $user->failedLoginAttempts,
                    'locked_until' => $user->lockedUntil,
                    'created_at' => $user->createdAt,
                    'updated_at' => $user->updatedAt,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to retrieve user: ' . $e->getMessage());
        }
    }

    /**
     * Create new user.
     *
     * POST /api/v1/users
     *
     * Request Body:
     * {
     *   "email": "user@example.com",
     *   "password": "SecurePass123!",
     *   "role": "customer"
     * }
     *
     * Response: 201 Created
     * {
     *   "success": true,
     *   "data": {
     *     "user_id": 123
     *   },
     *   "message": "User created successfully"
     * }
     *
     * Response: 422 Unprocessable Entity
     * {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {...}
     * }
     */
    public function create(): ResponseInterface
    {
        $commandBus = Services::commandBus();
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $command = new CreateUserCommand(
                name: $data['name'] ?? '',
                email: $data['email'] ?? '',
                password: $data['password'] ?? '',
                role: $data['role'] ?? 'customer'
            );

            $userId = $commandBus->dispatch($command);

            return $this->respondCreated([
                'success' => true,
                'data' => ['user_id' => $userId],
                'message' => 'User created successfully',
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to create user: ' . $e->getMessage());
        }
    }

    /**
     * Update existing user.
     *
     * PUT /api/v1/users/{id}
     *
     * @param mixed $id User ID
     *
     * Request Body:
     * {
     *   "name": "John Doe",
     *   "email": "john@example.com",
     *   "role": "customer",
     *   "status": "active"
     * }
     *
     * Response: 200 OK
     * {
     *   "success": true,
     *   "message": "User updated successfully"
     * }
     *
     * Response: 422 Unprocessable Entity
     * {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {...}
     * }
     */
    public function update($id = null): ResponseInterface
    {
        $commandBus = Services::commandBus();
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $command = new UpdateUserCommand(
                userId: (int) $id,
                name: $data['name'] ?? '',
                email: $data['email'] ?? '',
                role: $data['role'] ?? 'customer',
                status: $data['status'] ?? 'active'
            );

            $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'User updated successfully',
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to update user: ' . $e->getMessage());
        }
    }

    /**
     * Delete user (soft delete).
     *
     * DELETE /api/v1/users/{id}
     *
     * @param mixed $id User ID
     *
     * Response: 200 OK
     * {
     *   "success": true,
     *   "message": "User deleted successfully"
     * }
     *
     * Response: 404 Not Found
     * {
     *   "success": false,
     *   "message": "User not found"
     * }
     */
    public function delete($id = null): ResponseInterface
    {
        $commandBus = Services::commandBus();

        try {
            $command = new DeleteUserCommand(userId: (int) $id);
            $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'User deleted successfully',
            ]);
        } catch (DomainException $e) {
            return $this->failNotFound($e->getMessage());
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to delete user: ' . $e->getMessage());
        }
    }

    /**
     * Reset user password (admin operation).
     *
     * POST /api/v1/users/{id}/reset-password
     *
     * @param int $id User ID
     *
     * Request Body:
     * {
     *   "new_password": "NewSecurePass123!"
     * }
     *
     * Response: 200 OK
     * {
     *   "success": true,
     *   "message": "Password reset successfully"
     * }
     *
     * Response: 422 Unprocessable Entity
     * {
     *   "success": false,
     *   "message": "Validation failed",
     *   "errors": {...}
     * }
     */
    public function resetPassword(int $id): ResponseInterface
    {
        $commandBus = Services::commandBus();
        assert($this->request instanceof IncomingRequest);

        try {
            $data = $this->request->getJSON(true);
            assert(is_array($data));

            $command = new ChangeUserPasswordCommand(
                userId: $id,
                newPassword: $data['new_password'] ?? ''
            );

            $commandBus->dispatch($command);

            return $this->respond([
                'success' => true,
                'message' => 'Password reset successfully',
            ]);
        } catch (ValidationException $e) {
            return $this->fail($e->getMessage(), 422);
        } catch (DomainException $e) {
            return $this->fail($e->getMessage(), 400);
        } catch (\Throwable $e) {
            return $this->failServerError('Failed to reset password: ' . $e->getMessage());
        }
    }
}

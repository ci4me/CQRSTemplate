<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordCommand;
use App\Domain\User\Commands\CreateUser\CreateUserCommand;
use App\Domain\User\Commands\DeleteUser\DeleteUserCommand;
use App\Domain\User\Commands\UpdateUser\UpdateUserCommand;
use App\Domain\User\DTOs\UserDTO;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersQuery;
use App\Domain\User\Queries\GetUserById\GetUserByIdQuery;
use App\Infrastructure\Http\ApiResponse;
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

            // DTOs already carry the public surface — array_map flattens them
            // to plain arrays for the JSON envelope. array_values rebuilds
            // the array as a sequential list (PHPStan rejects associative
            // arrays for ApiResponse::paginated's list<mixed> param).
            $users = array_values(array_map(
                static fn (UserDTO $user): array => self::userToArray($user),
                $result['data']
            ));

            return ApiResponse::paginated(
                data: $users,
                page: $result['page'],
                perPage: $result['perPage'],
                total: $result['total'],
                lastPage: $result['lastPage']
            );
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to retrieve users: ' . $e->getMessage());
        }
    }

    /**
     * @return array{id: ?int, name: string, email: string, role: string, status: string, failed_login_attempts: int, locked_until: ?string, created_at: string, updated_at: ?string}
     */
    private static function userToArray(UserDTO $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'failed_login_attempts' => $user->failedLoginAttempts,
            'locked_until' => $user->lockedUntil,
            'created_at' => $user->createdAt,
            'updated_at' => $user->updatedAt,
        ];
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
                return ApiResponse::notFound('User not found');
            }

            return ApiResponse::ok(self::userToArray($user));
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to retrieve user: ' . $e->getMessage());
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

            return ApiResponse::created(['user_id' => $userId]);
        } catch (ValidationException $e) {
            return ApiResponse::problem(422, 'Validation failed', $e->getMessage());
        } catch (DomainException $e) {
            return ApiResponse::problem(400, 'Bad Request', $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to create user: ' . $e->getMessage());
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
                status: $data['status'] ?? 'active',
                updatedBy: Services::actorResolver()->resolve($this->request)
            );

            $commandBus->dispatch($command);

            return ApiResponse::ok(['updated' => true]);
        } catch (ValidationException $e) {
            return ApiResponse::problem(422, 'Validation failed', $e->getMessage());
        } catch (DomainException $e) {
            return ApiResponse::problem(400, 'Bad Request', $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to update user: ' . $e->getMessage());
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
            $command = new DeleteUserCommand(
                userId: (int) $id,
                deletedBy: Services::actorResolver()->resolve($this->request)
            );
            $commandBus->dispatch($command);

            return ApiResponse::ok(['deleted' => true]);
        } catch (DomainException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to delete user: ' . $e->getMessage());
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
                newPassword: $data['new_password'] ?? '',
                changedBy: Services::actorResolver()->resolve($this->request)
            );

            $commandBus->dispatch($command);

            return ApiResponse::ok(['password_reset' => true]);
        } catch (ValidationException $e) {
            return ApiResponse::problem(422, 'Validation failed', $e->getMessage());
        } catch (DomainException $e) {
            return ApiResponse::problem(400, 'Bad Request', $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::problem(500, 'Server Error', 'Failed to reset password: ' . $e->getMessage());
        }
    }
}

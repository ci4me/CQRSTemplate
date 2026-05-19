<?php

declare(strict_types=1);

namespace App\Controllers\Domain\User;

use App\Controllers\BaseController;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordCommand;
use App\Domain\User\Commands\DeleteUser\DeleteUserCommand;
use App\Domain\User\Commands\RegisterUser\RegisterUserCommand;
use App\Domain\User\Commands\UpdateUser\UpdateUserCommand;
use App\Domain\User\Queries\GetAllUsers\GetAllUsersQuery;
use App\Domain\User\Queries\GetUserById\GetUserByIdQuery;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Session\Session;
use Config\Services;

/**
 * User Controller - Traditional MVC interface for user management.
 *
 * Responsibilities:
 * - Handle HTTP requests for web interface
 * - Render views with user data
 * - Process form submissions
 * - Handle validation errors and redisplay forms
 * - Redirect with flash messages after operations
 * - Dispatch commands and queries via buses
 *
 * CQRS Pattern:
 * - Write operations use CommandBus (store, update, delete, storePassword)
 * - Read operations use QueryBus (index, show, create form, edit form)
 * - Thin controller - no business logic, only HTTP orchestration
 *
 * Views Location: app/Views/admin/users/
 *
 * @package App\Controllers\Domain\User
 */
final class UserController extends BaseController
{
    /**
     * Display paginated list of users.
     *
     * GET /admin/users
     *
     * View: admin/users/index.php
     */
    public function index(): string
    {
        $queryBus = Services::queryBus();

        $pageParam = $this->request->getGet('page');
        $page = is_numeric($pageParam) ? (int) $pageParam : 1;

        $searchParam = $this->request->getGet('search');
        $search = is_string($searchParam) ? $searchParam : '';

        $query = new GetAllUsersQuery(
            page: $page,
            perPage: 20,
            includeInactive: false,
            searchTerm: $search
        );

        $result = $queryBus->ask($query);

        return view('admin/users/index', [
            'users' => $result['data'],
            'pagination' => $result,
            'search' => $search,
        ]);
    }

    /**
     * Display single user details.
     *
     * GET /admin/users/{id}
     *
     * View: admin/users/show.php
     */
    public function show(int $id): string|RedirectResponse
    {
        $queryBus = Services::queryBus();

        try {
            $query = new GetUserByIdQuery(id: $id);
            $user = $queryBus->ask($query);

            if ($user === null) {
                return redirect()->to('/admin/users')
                    ->with('error', 'User not found');
            }

            return view('admin/users/show', ['user' => $user]);
        } catch (\Throwable $e) {
            return redirect()->to('/admin/users')
                ->with('error', 'Failed to retrieve user: ' . $e->getMessage());
        }
    }

    /**
     * Display create user form.
     *
     * GET /admin/users/create
     *
     * View: admin/users/create.php
     */
    public function create(): string
    {
        return view('admin/users/create');
    }

    /**
     * Store new user.
     *
     * POST /admin/users
     *
     * Redirects to: /admin/users (on success) or back (on error)
     */
    public function store(): RedirectResponse
    {
        $commandBus = Services::commandBus();
        $session = session();
        assert($session instanceof Session);

        try {
            $nameParam = $this->request->getPost('name');
            $name = is_string($nameParam) ? $nameParam : '';

            $emailParam = $this->request->getPost('email');
            $email = is_string($emailParam) ? $emailParam : '';

            $passwordParam = $this->request->getPost('password');
            $password = is_string($passwordParam) ? $passwordParam : '';

            $roleParam = $this->request->getPost('role');
            $role = is_string($roleParam) ? $roleParam : 'customer';

            $command = new RegisterUserCommand(
                name: $name,
                email: $email,
                password: $password,
                role: $role
            );

            $userId = $commandBus->dispatch($command);

            $session->setFlashdata('success', 'User created successfully');
            return redirect()->to("/admin/users/{$userId}");
        } catch (ValidationException $e) {
            $session->setFlashdata('errors', $e->getErrors());
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (DomainException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (\Throwable $e) {
            $session->setFlashdata('error', 'Failed to create user: ' . $e->getMessage());
            return redirect()->back()->withInput();
        }
    }

    /**
     * Display edit user form.
     *
     * GET /admin/users/{id}/edit
     *
     * View: admin/users/edit.php
     */
    public function edit(int $id): string|RedirectResponse
    {
        $queryBus = Services::queryBus();

        try {
            $query = new GetUserByIdQuery(id: $id);
            $user = $queryBus->ask($query);

            if ($user === null) {
                return redirect()->to('/admin/users')
                    ->with('error', 'User not found');
            }

            return view('admin/users/edit', ['user' => $user]);
        } catch (\Throwable $e) {
            return redirect()->to('/admin/users')
                ->with('error', 'Failed to retrieve user: ' . $e->getMessage());
        }
    }

    /**
     * Update existing user.
     *
     * POST /admin/users/{id}
     *
     * Redirects to: /admin/users/{id} (on success) or back (on error)
     */
    public function update(int $id): RedirectResponse
    {
        $commandBus = Services::commandBus();
        $session = session();
        assert($session instanceof Session);

        try {
            $nameParam = $this->request->getPost('name');
            $name = is_string($nameParam) ? $nameParam : '';

            $emailParam = $this->request->getPost('email');
            $email = is_string($emailParam) ? $emailParam : '';

            $roleParam = $this->request->getPost('role');
            $role = is_string($roleParam) ? $roleParam : 'customer';

            $statusParam = $this->request->getPost('status');
            $status = is_string($statusParam) ? $statusParam : 'active';

            $command = new UpdateUserCommand(
                userId: $id,
                name: $name,
                email: $email,
                role: $role,
                status: $status
            );

            $commandBus->dispatch($command);

            $session->setFlashdata('success', 'User updated successfully');
            return redirect()->to("/admin/users/{$id}");
        } catch (ValidationException $e) {
            $session->setFlashdata('errors', $e->getErrors());
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (DomainException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (\Throwable $e) {
            $session->setFlashdata('error', 'Failed to update user: ' . $e->getMessage());
            return redirect()->back()->withInput();
        }
    }

    /**
     * Delete user (soft delete with confirmation).
     *
     * POST /admin/users/{id}/delete
     *
     * Redirects to: /admin/users (on success) or back (on error)
     */
    public function delete(int $id): RedirectResponse
    {
        $commandBus = Services::commandBus();
        $session = session();
        assert($session instanceof Session);

        try {
            $command = new DeleteUserCommand(
                userId: $id,
                deletedBy: Services::actorResolver()->resolve($this->request)
            );
            $commandBus->dispatch($command);

            $session->setFlashdata('success', 'User deleted successfully');
            return redirect()->to('/admin/users');
        } catch (DomainException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back();
        } catch (\Throwable $e) {
            $session->setFlashdata('error', 'Failed to delete user: ' . $e->getMessage());
            return redirect()->back();
        }
    }

    /**
     * Display password reset form.
     *
     * GET /admin/users/{id}/reset-password
     *
     * View: admin/users/reset_password.php
     */
    public function resetPassword(int $id): string|RedirectResponse
    {
        $queryBus = Services::queryBus();

        try {
            $query = new GetUserByIdQuery(id: $id);
            $user = $queryBus->ask($query);

            if ($user === null) {
                return redirect()->to('/admin/users')
                    ->with('error', 'User not found');
            }

            return view('admin/users/reset_password', ['user' => $user]);
        } catch (\Throwable $e) {
            return redirect()->to('/admin/users')
                ->with('error', 'Failed to retrieve user: ' . $e->getMessage());
        }
    }

    /**
     * Process password reset.
     *
     * POST /admin/users/{id}/reset-password
     *
     * Redirects to: /admin/users/{id} (on success) or back (on error)
     */
    public function storePassword(int $id): RedirectResponse
    {
        $commandBus = Services::commandBus();
        $session = session();
        assert($session instanceof Session);

        try {
            $newPasswordParam = $this->request->getPost('new_password');
            $newPassword = is_string($newPasswordParam) ? $newPasswordParam : '';

            $command = new ChangeUserPasswordCommand(
                userId: $id,
                newPassword: $newPassword,
                changedBy: Services::actorResolver()->resolve($this->request)
            );

            $commandBus->dispatch($command);

            $session->setFlashdata('success', 'Password reset successfully');
            return redirect()->to("/admin/users/{$id}");
        } catch (ValidationException $e) {
            $session->setFlashdata('errors', $e->getErrors());
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (DomainException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (\Throwable $e) {
            $session->setFlashdata('error', 'Failed to reset password: ' . $e->getMessage());
            return redirect()->back()->withInput();
        }
    }
}

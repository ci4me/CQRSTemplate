<?php

declare(strict_types=1);

namespace App\Controllers\Domain\Auth;

use App\Controllers\BaseController;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\User\Commands\RegisterUser\RegisterUserCommand;
use App\Infrastructure\Auth\Commands\LoginUser\LoginUserCommand;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;

/**
 * Traditional web-based authentication controller.
 * Handles HTML forms with sessions.
 */
final class AuthController extends BaseController
{
    public function showRegister(): string
    {
        return view('auth/register');
    }

    public function register(): ResponseInterface
    {
        $commandBus = service('commandBus');
        $session = session();
        assert($session instanceof Session);

        try {
            $name = $this->request->getPost('name');
            $email = $this->request->getPost('email');
            $password = $this->request->getPost('password');
            $role = $this->request->getPost('role') ?? 'customer';

            $command = new RegisterUserCommand(
                name: is_string($name) ? $name : '',
                email: is_string($email) ? $email : '',
                password: is_string($password) ? $password : '',
                role: is_string($role) ? $role : 'customer'
            );

            $commandBus->dispatch($command);

            $session->setFlashdata('success', 'Registration successful! Please log in.');
            return redirect()->to('/auth/login');
        } catch (ValidationException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        } catch (DomainException $e) {
            $session->setFlashdata('error', $e->getMessage());
            return redirect()->back()->withInput();
        }
    }

    public function showLogin(): string
    {
        return view('auth/login');
    }

    public function login(): ResponseInterface
    {
        $commandBus = service('commandBus');
        $session = session();
        assert($session instanceof Session);

        try {
            $email = $this->request->getPost('email');
            $password = $this->request->getPost('password');

            $command = new LoginUserCommand(
                email: is_string($email) ? $email : '',
                password: is_string($password) ? $password : ''
            );

            $result = $commandBus->dispatch($command);

            if ($result->isSuccess()) {
                $session->regenerate(true);

                $session->set([
                    'user_id' => $result->user->getId(),
                    'email' => $result->user->getEmail()->getValue(),
                    'role' => $result->user->getRole()->value,
                    'logged_in' => true,
                ]);

                return redirect()->to('/dashboard');
            }

            $session->setFlashdata('error', 'Invalid credentials');
            return redirect()->back()->withInput();
        } catch (\Throwable $e) {
            $session->setFlashdata('error', 'Login failed');
            return redirect()->back()->withInput();
        }
    }

    public function logout(): ResponseInterface
    {
        $session = session();
        assert($session instanceof Session);
        $session->destroy();

        return redirect()->to('/auth/login')->with('success', 'Logged out successfully');
    }
}

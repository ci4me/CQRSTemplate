<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Session\Session;

final class Home extends BaseController
{
    public function index(): RedirectResponse
    {
        $session = session();
        assert($session instanceof Session);

        if ($session->has('user_id')) {
            return redirect()->to('/dashboard');
        }

        return redirect()->to('/auth/login');
    }

    public function dashboard(): string
    {
        return view('dashboard', ['title' => 'Dashboard']);
    }
}

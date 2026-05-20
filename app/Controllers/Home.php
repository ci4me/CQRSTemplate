<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Session\Session;

/**
 * Home.
 *
 * @todo Auto-generated docblock — review and replace this description.
 */
final class Home extends BaseController
{
    /**
     * index.
     *
     * @return RedirectResponse
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function index(): RedirectResponse
    {
        $session = session();
        assert($session instanceof Session);

        if ($session->has('user_id')) {
            return redirect()->to('/dashboard');
        }

        return redirect()->to('/auth/login');
    }

    /**
     * dashboard.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function dashboard(): string
    {
        return view('dashboard', ['title' => 'Dashboard']);
    }
}

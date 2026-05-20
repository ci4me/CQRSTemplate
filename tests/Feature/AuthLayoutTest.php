<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * Auth views (login, register) extend the new layouts/auth shell (E4).
 * These tests assert the shared shell renders + the page content slots
 * in correctly + no inline <style> creeps back in.
 */
final class AuthLayoutTest extends FeatureTestCase
{
    protected bool $authenticateByDefault = false;

    public function test_login_view_renders_via_auth_layout(): void
    {
        $result = $this->get('/auth/login');

        $result->assertOK();
        $body = (string) $result->response()->getBody();

        // Page content (login form)
        $this->assertStringContainsString('id="email"', $body);
        $this->assertStringContainsString('id="password"', $body);
        $this->assertStringContainsString('csrf', strtolower($body), 'csrf field present');

        // Shared layout artefacts
        $this->assertStringContainsString('/assets/css/auth.css', $body);
        $this->assertStringContainsString('<title>', $body);

        // CSP-clean: no inline <style> blocks
        $this->assertStringNotContainsString('<style', $body);
    }

    public function test_register_view_renders_via_auth_layout(): void
    {
        $result = $this->get('/auth/register');

        $result->assertOK();
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('name="role" value="customer"', $body);
        $this->assertStringContainsString('/assets/css/auth.css', $body);
        $this->assertStringNotContainsString('<style', $body);
    }
}

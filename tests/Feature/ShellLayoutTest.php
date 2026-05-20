<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * The shell rendering matters because the new layout (E1) replaced the
 * demo navbar. These tests assert that the shell still renders for the
 * existing routes that extend `layout` (cookies index, dashboard) and
 * that the sidebar / locale switcher / user menu are present.
 */
final class ShellLayoutTest extends FeatureTestCase
{
    public function test_dashboard_renders_in_the_shell(): void
    {
        $result = $this->get('/dashboard');

        $result->assertOK();
        $body = (string) $result->response()->getBody();

        $this->assertStringContainsString('navbar', $body, 'top bar present');
        $this->assertStringContainsString('Dashboard', $body, 'sidebar item present');
        $this->assertStringContainsString('Cookies', $body, 'cookies sidebar item present (admin sees everything)');
    }

    public function test_cookies_index_extends_the_shell(): void
    {
        $result = $this->get('/cookies');

        $result->assertOK();
        $body = (string) $result->response()->getBody();

        // Brand text is present in the top bar regardless of locale.
        $this->assertStringContainsString('ERP Template', $body);

        // The legacy view marker still renders inside the new shell.
        $this->assertStringContainsString('cookies/index', $body);

        // Locale switcher renders.
        $this->assertStringContainsString('?locale=', $body);

        // Notification bell renders.
        $this->assertStringContainsString('🔔', $body);
    }
}

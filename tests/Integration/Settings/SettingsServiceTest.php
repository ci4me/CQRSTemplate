<?php

declare(strict_types=1);

namespace Tests\Integration\Settings;

use App\Infrastructure\Settings\SettingsService;
use Config\Database;
use Tests\Support\IntegrationTestCase;

final class SettingsServiceTest extends IntegrationTestCase
{
    public function test_returns_default_for_missing_key(): void
    {
        $svc = new SettingsService();

        $this->assertSame('fallback', $svc->get('billing.terms', 'fallback'));
        $this->assertFalse($svc->has('billing.terms'));
    }

    public function test_set_persists_and_subsequent_get_returns_value(): void
    {
        $svc = new SettingsService();

        $svc->set('billing.default_terms_days', 30, type: 'int', description: 'Net days');
        $svc->clearCache();

        $this->assertSame(30, $svc->get('billing.default_terms_days'));
        $this->assertTrue($svc->has('billing.default_terms_days'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $svc = new SettingsService();

        $svc->set('flag.enabled', false);
        $svc->set('flag.enabled', true);

        $this->assertTrue($svc->get('flag.enabled'));
    }

    public function test_complex_values_round_trip_through_json(): void
    {
        $svc = new SettingsService();

        $payload = ['tiers' => [1, 2, 3], 'name' => 'Gold', 'live' => true];
        $svc->set('billing.tiers', $payload, type: 'array');
        $svc->clearCache();

        $this->assertSame($payload, $svc->get('billing.tiers'));
    }

    public function test_tenant_scoped_setting_is_independent_of_global(): void
    {
        $svc = new SettingsService();

        $svc->set('flag.show_dashboard', true);
        $svc->set('flag.show_dashboard', false, tenantId: 42);
        $svc->clearCache();

        $this->assertTrue($svc->get('flag.show_dashboard'));
        $this->assertFalse($svc->get('flag.show_dashboard', null, 42));
    }

    public function test_tenant_scoped_setting_does_not_fall_back_automatically(): void
    {
        // Caller must opt into fallback; the service keeps the lookup explicit.
        $svc = new SettingsService();

        $svc->set('flag.exclusive', 'global-value');

        $this->assertSame('global-value', $svc->get('flag.exclusive'));
        $this->assertNull($svc->get('flag.exclusive', null, 99));
    }

    public function test_forget_removes_row_and_invalidates_cache(): void
    {
        $svc = new SettingsService();

        $svc->set('temp.value', 'x');
        $this->assertTrue($svc->has('temp.value'));

        $svc->forget('temp.value');
        $this->assertFalse($svc->has('temp.value'));
        $this->assertSame('default', $svc->get('temp.value', 'default'));
    }

    public function test_forget_is_idempotent_for_missing_key(): void
    {
        $svc = new SettingsService();

        $svc->forget('never.existed');
        $this->assertFalse($svc->has('never.existed'));
    }

    public function test_cache_hits_avoid_second_db_read(): void
    {
        $svc = new SettingsService();
        $svc->set('cached.key', 'first');

        // Delete the underlying row directly. With a cache hit the
        // service must still return the originally read value within this
        // request lifetime.
        Database::connect()->table('settings')->where('key_name', 'cached.key')->delete();

        $this->assertSame('first', $svc->get('cached.key'));

        $svc->clearCache();
        $this->assertSame('default', $svc->get('cached.key', 'default'));
    }
}

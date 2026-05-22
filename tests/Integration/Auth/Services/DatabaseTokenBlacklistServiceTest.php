<?php

declare(strict_types=1);

namespace Tests\Integration\Auth\Services;

use App\Infrastructure\Auth\Services\DatabaseTokenBlacklistService;
use Config\Database;
use Tests\Support\IntegrationTestCase;

final class DatabaseTokenBlacklistServiceTest extends IntegrationTestCase
{
    public function test_blacklisted_token_is_reported_as_blacklisted(): void
    {
        $service = new DatabaseTokenBlacklistService();

        $service->blacklist('access.jwt.token');

        $this->assertTrue($service->isBlacklisted('access.jwt.token'));
        $this->assertFalse($service->isBlacklisted('different.jwt.token'));
    }

    public function test_blacklist_is_idempotent_for_duplicate_token(): void
    {
        $service = new DatabaseTokenBlacklistService();

        $service->blacklist('repeat.jwt.token');
        $service->blacklist('repeat.jwt.token');

        // Only one row should exist — the duplicate insert is silently swallowed.
        $count = Database::connect()
            ->table('token_blacklist')
            ->countAllResults();
        $this->assertSame(1, $count);
    }

    public function test_purge_expired_drops_rows_older_than_now(): void
    {
        $service = new DatabaseTokenBlacklistService();

        // Insert a real row, then forcibly age it.
        $service->blacklist('about-to-expire.jwt');
        Database::connect()
            ->table('token_blacklist')
            ->where('jti', hash('sha256', 'about-to-expire.jwt'))
            ->update(['expires_at' => '2000-01-01 00:00:00']);

        // Add a non-expired row that must survive.
        $service->blacklist('still-valid.jwt');

        $purged = $service->purgeExpired();

        $this->assertSame(1, $purged);
        $this->assertFalse($service->isBlacklisted('about-to-expire.jwt'));
        $this->assertTrue($service->isBlacklisted('still-valid.jwt'));
    }

    public function test_expired_tokens_are_not_treated_as_blacklisted(): void
    {
        $service = new DatabaseTokenBlacklistService();
        $service->blacklist('aged.jwt');
        Database::connect()
            ->table('token_blacklist')
            ->where('jti', hash('sha256', 'aged.jwt'))
            ->update(['expires_at' => '1999-12-31 23:59:59']);

        $this->assertFalse($service->isBlacklisted('aged.jwt'));
    }
}

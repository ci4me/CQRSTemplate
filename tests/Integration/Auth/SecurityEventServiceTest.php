<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Infrastructure\Auth\Services\SecurityEventService;
use Config\Database;
use Tests\Support\IntegrationTestCase;

/**
 * Pins the persistence contract of SecurityEventService against the real
 * `security_events` table. All convenience methods (logLoginSuccess,
 * logLoginFailure, logPasswordChanged, logSuspiciousActivity, logTokenTheft)
 * are thin wrappers around logEvent — we assert each one writes a row with
 * the correct event_type, severity, and JSON-encoded metadata.
 *
 * getRecentEvents() is tested separately for ordering and limit.
 */
final class SecurityEventServiceTest extends IntegrationTestCase
{
    private const string IP = '198.51.100.42';

    public function test_log_event_persists_row_with_all_columns(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('a@example.test');

        $service->logEvent('custom_event', 'high', $userId, self::IP, 'Something happened', ['k' => 'v']);

        $row = $this->fetchOnly();
        $this->assertSame('custom_event', $row['event_type']);
        $this->assertSame('high', $row['severity']);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame(self::IP, $row['ip_address']);
        $this->assertSame('Something happened', $row['description']);
        $this->assertSame(['k' => 'v'], json_decode((string) $row['metadata'], true));
    }

    public function test_log_event_persists_null_metadata_when_omitted(): void
    {
        $service = new SecurityEventService();

        $service->logEvent('no_meta', 'low', null, self::IP, 'No metadata supplied');

        $this->assertNull($this->fetchOnly()['metadata']);
    }

    public function test_log_event_accepts_null_user_id_for_anonymous_events(): void
    {
        // login_failure & rate-limit-exceeded fire BEFORE we know which user.
        $service = new SecurityEventService();

        $service->logEvent('anonymous_event', 'medium', null, self::IP, 'Pre-auth event');

        $this->assertNull($this->fetchOnly()['user_id']);
    }

    public function test_log_login_success_records_low_severity_event(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('ok@example.test');

        $service->logLoginSuccess($userId, self::IP, 'Mozilla/5.0');

        $row = $this->fetchOnly();
        $this->assertSame('login_success', $row['event_type']);
        $this->assertSame('low', $row['severity']);
        $this->assertSame($userId, (int) $row['user_id']);
        $this->assertSame(['user_agent' => 'Mozilla/5.0'], json_decode((string) $row['metadata'], true));
    }

    public function test_log_login_failure_records_medium_severity_with_null_user(): void
    {
        $service = new SecurityEventService();

        $service->logLoginFailure('attacker@example.test', self::IP, 'invalid_password', 'curl/8');

        $row = $this->fetchOnly();
        $this->assertSame('login_failure', $row['event_type']);
        $this->assertSame('medium', $row['severity']);
        $this->assertNull($row['user_id']);
        $this->assertStringContainsString('invalid_password', $row['description']);
        $meta = json_decode((string) $row['metadata'], true);
        $this->assertSame('attacker@example.test', $meta['email']);
        $this->assertSame('invalid_password', $meta['reason']);
        $this->assertSame('curl/8', $meta['user_agent']);
    }

    public function test_log_password_changed_records_high_severity_with_timestamp_metadata(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('pw@example.test');

        $service->logPasswordChanged($userId, self::IP);

        $row = $this->fetchOnly();
        $this->assertSame('password_changed', $row['event_type']);
        $this->assertSame('high', $row['severity']);
        $meta = json_decode((string) $row['metadata'], true);
        $this->assertArrayHasKey('changed_at', $meta);
    }

    public function test_log_suspicious_activity_is_always_critical(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('sus@example.test');

        $service->logSuspiciousActivity($userId, self::IP, 'Impossible travel', ['country' => 'XX']);

        $row = $this->fetchOnly();
        $this->assertSame('suspicious_activity', $row['event_type']);
        $this->assertSame('critical', $row['severity']);
        $this->assertSame(['country' => 'XX'], json_decode((string) $row['metadata'], true));
    }

    public function test_log_suspicious_activity_accepts_null_user_id(): void
    {
        $service = new SecurityEventService();

        $service->logSuspiciousActivity(null, self::IP, 'Pre-auth anomaly', []);

        $this->assertNull($this->fetchOnly()['user_id']);
    }

    public function test_log_token_theft_records_critical_event_with_jti(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('theft@example.test');

        $service->logTokenTheft($userId, self::IP, 'jti-stolen-123');

        $row = $this->fetchOnly();
        $this->assertSame('token_theft_detected', $row['event_type']);
        $this->assertSame('critical', $row['severity']);
        $this->assertSame(['jti' => 'jti-stolen-123'], json_decode((string) $row['metadata'], true));
    }

    public function test_get_recent_events_returns_user_events_in_reverse_chronological(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('recent@example.test');
        $this->insertEvent($userId, 'login_success', '2026-05-01 10:00:00');
        $this->insertEvent($userId, 'password_changed', '2026-05-02 10:00:00');
        $this->insertEvent($userId, 'login_success', '2026-05-03 10:00:00');

        $events = $service->getRecentEvents($userId, 10);

        $this->assertCount(3, $events);
        $this->assertSame('2026-05-03 10:00:00', $events[0]['created_at']);
        $this->assertSame('2026-05-01 10:00:00', $events[2]['created_at']);
    }

    public function test_get_recent_events_respects_limit(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('limit@example.test');
        for ($i = 0; $i < 5; $i++) {
            $this->insertEvent($userId, 'login_success', sprintf('2026-05-%02d 10:00:00', $i + 1));
        }

        $this->assertCount(2, $service->getRecentEvents($userId, 2));
    }

    public function test_get_recent_events_returns_empty_for_user_with_no_events(): void
    {
        $service = new SecurityEventService();
        $userId = $this->insertUser('quiet@example.test');

        $this->assertSame([], $service->getRecentEvents($userId, 10));
    }

    public function test_get_recent_events_isolates_by_user(): void
    {
        // SECURITY: getRecentEvents must scope strictly by user_id; nobody
        // should be able to read another user's security audit trail.
        $service = new SecurityEventService();
        $alice = $this->insertUser('alice@example.test');
        $bob = $this->insertUser('bob@example.test');
        $this->insertEvent($alice, 'login_success', '2026-05-01 10:00:00');
        $this->insertEvent($bob, 'login_success', '2026-05-01 11:00:00');

        $result = $service->getRecentEvents($alice, 10);

        $this->assertCount(1, $result);
        $this->assertSame($alice, (int) $result[0]['user_id']);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function insertUser(string $email): int
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('users')->insert([
            'name' => 'SecEvt Test',
            'email' => $email,
            'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$xx$' . str_repeat('a', 43),
            'role' => 'customer',
            'status' => 'active',
            'failed_login_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) Database::connect()->insertID();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOnly(): array
    {
        $rows = Database::connect()->table('security_events')->get()->getResultArray();
        $this->assertCount(1, $rows, 'expected exactly one event row');

        return $rows[0];
    }

    private function insertEvent(int $userId, string $type, string $createdAt): void
    {
        Database::connect()->table('security_events')->insert([
            'event_type' => $type,
            'severity' => 'low',
            'user_id' => $userId,
            'ip_address' => self::IP,
            'description' => 'seeded',
            'metadata' => null,
            'created_at' => $createdAt,
        ]);
    }
}

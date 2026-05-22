<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Infrastructure\Auth\Services\LoginAttemptTracker;
use Config\Database;
use Tests\Support\IntegrationTestCase;

/**
 * Pins LoginAttemptTracker against the real `login_attempts` table.
 *
 * Brute-force semantics are constants in the class (5 failures / 300 s),
 * so we exercise them at the boundary: 4 failures = quiet, 5 = detected.
 * Window scoping is tested with an out-of-window failure that must NOT
 * count toward the threshold.
 */
final class LoginAttemptTrackerTest extends IntegrationTestCase
{
    private const string EMAIL = 'lockout-target@example.test';
    private const string IP_BAD = '203.0.113.99';
    private const string IP_GOOD = '203.0.113.1';

    public function test_record_successful_attempt_inserts_row(): void
    {
        $tracker = new LoginAttemptTracker();
        $userId = $this->insertUser(self::EMAIL);

        $tracker->recordAttempt(self::EMAIL, $userId, self::IP_GOOD, 'Mozilla/5.0', true);

        $this->assertDatabaseHas('login_attempts', [
            'email' => self::EMAIL,
            'user_id' => $userId,
            'success' => 1,
            'failure_reason' => null,
        ]);
    }

    public function test_record_failed_attempt_inserts_with_reason(): void
    {
        $tracker = new LoginAttemptTracker();

        $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, 'curl/8', false, 'invalid_password');

        $this->assertDatabaseHas('login_attempts', [
            'email' => self::EMAIL,
            'user_id' => null,
            'success' => 0,
            'failure_reason' => 'invalid_password',
        ]);
    }

    public function test_record_attempt_accepts_null_user_agent(): void
    {
        $tracker = new LoginAttemptTracker();

        $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, null, false, 'no_such_user');

        $row = Database::connect()->table('login_attempts')->get()->getRowArray();
        $this->assertNotNull($row);
        $this->assertNull($row['user_agent']);
    }

    public function test_is_brute_force_detected_returns_false_below_threshold(): void
    {
        $tracker = new LoginAttemptTracker();
        for ($i = 0; $i < 4; $i++) { // 4 < 5 = threshold
            $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, null, false, 'invalid_password');
        }

        $this->assertFalse($tracker->isBruteForceDetected(self::IP_BAD));
    }

    public function test_is_brute_force_detected_returns_true_at_threshold(): void
    {
        $tracker = new LoginAttemptTracker();
        for ($i = 0; $i < 5; $i++) {
            $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, null, false, 'invalid_password');
        }

        $this->assertTrue($tracker->isBruteForceDetected(self::IP_BAD));
    }

    public function test_is_brute_force_detected_ignores_successful_attempts(): void
    {
        // SECURITY: a legitimate user's 5 successes must never trigger the
        // brute-force flag. Only `success=false` rows count.
        $tracker = new LoginAttemptTracker();
        $userId = $this->insertUser(self::EMAIL);
        for ($i = 0; $i < 5; $i++) {
            $tracker->recordAttempt(self::EMAIL, $userId, self::IP_GOOD, null, true);
        }

        $this->assertFalse($tracker->isBruteForceDetected(self::IP_GOOD));
    }

    public function test_is_brute_force_detected_ignores_failures_outside_window(): void
    {
        // Insert 5 old failures (>5 min ago) — must NOT trip the detector.
        $past = date('Y-m-d H:i:s', time() - 3600);
        for ($i = 0; $i < 5; $i++) {
            $this->seedAttempt(self::IP_BAD, success: false, createdAt: $past);
        }

        $this->assertFalse((new LoginAttemptTracker())->isBruteForceDetected(self::IP_BAD));
    }

    public function test_is_brute_force_detected_is_scoped_by_ip(): void
    {
        $tracker = new LoginAttemptTracker();
        for ($i = 0; $i < 5; $i++) {
            $tracker->recordAttempt(self::EMAIL, null, '10.0.0.1', null, false, 'invalid_password');
        }

        // A completely unrelated IP must NOT inherit the brute-force flag.
        $this->assertFalse($tracker->isBruteForceDetected('10.0.0.2'));
    }

    public function test_get_recent_attempts_returns_user_attempts_in_reverse_order(): void
    {
        $userId = $this->insertUser(self::EMAIL);
        $this->seedAttempt(self::IP_BAD, success: false, createdAt: '2026-05-01 10:00:00', userId: $userId);
        $this->seedAttempt(self::IP_BAD, success: true, createdAt: '2026-05-02 10:00:00', userId: $userId);
        $this->seedAttempt(self::IP_BAD, success: false, createdAt: '2026-05-03 10:00:00', userId: $userId);

        $rows = (new LoginAttemptTracker())->getRecentAttempts($userId, 10);

        $this->assertCount(3, $rows);
        $this->assertSame('2026-05-03 10:00:00', $rows[0]['created_at']);
        $this->assertSame('2026-05-01 10:00:00', $rows[2]['created_at']);
    }

    public function test_get_recent_attempts_respects_limit(): void
    {
        $userId = $this->insertUser(self::EMAIL);
        for ($i = 1; $i <= 5; $i++) {
            $this->seedAttempt(self::IP_BAD, false, sprintf('2026-05-%02d 10:00:00', $i), $userId);
        }

        $this->assertCount(2, (new LoginAttemptTracker())->getRecentAttempts($userId, 2));
    }

    public function test_get_recent_attempts_returns_empty_for_user_with_no_attempts(): void
    {
        $userId = $this->insertUser(self::EMAIL);

        $this->assertSame([], (new LoginAttemptTracker())->getRecentAttempts($userId, 10));
    }

    public function test_get_failed_attempt_count_counts_only_failures_in_window(): void
    {
        $tracker = new LoginAttemptTracker();
        // 3 failures inside the window
        for ($i = 0; $i < 3; $i++) {
            $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, null, false, 'invalid_password');
        }
        // 1 success inside the window (must NOT be counted)
        $tracker->recordAttempt(self::EMAIL, null, self::IP_BAD, null, true);
        // 1 failure outside the window (must NOT be counted)
        $this->seedAttempt(self::IP_BAD, false, date('Y-m-d H:i:s', time() - 3600), null, self::EMAIL);

        $this->assertSame(3, $tracker->getFailedAttemptCount(self::EMAIL, 600));
    }

    public function test_get_failed_attempt_count_returns_zero_for_unknown_email(): void
    {
        $this->assertSame(0, (new LoginAttemptTracker())->getFailedAttemptCount('unknown@example.test', 600));
    }

    public function test_cleanup_removes_records_older_than_cutoff(): void
    {
        // Seed 2 old + 1 recent (no FK because user_id is nullable & we want pure date logic).
        $this->seedAttempt(self::IP_BAD, false, date('Y-m-d H:i:s', time() - 86400 * 100));
        $this->seedAttempt(self::IP_BAD, false, date('Y-m-d H:i:s', time() - 86400 * 95));
        $this->seedAttempt(self::IP_BAD, false, date('Y-m-d H:i:s', time() - 60));

        (new LoginAttemptTracker())->cleanup(daysToKeep: 90);

        // Side effect: only the recent row should remain. (SQLite returns 0 from
        // affected-row count after delete in this code path, so we don't assert
        // the return value — we assert the durable state.)
        $remaining = Database::connect()->table('login_attempts')->countAllResults();
        $this->assertSame(1, $remaining);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function insertUser(string $email): int
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('users')->insert([
            'name' => 'LoginAttempt Test',
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

    private function seedAttempt(
        string $ip,
        bool $success,
        string $createdAt,
        ?int $userId = null,
        string $email = self::EMAIL,
    ): void {
        Database::connect()->table('login_attempts')->insert([
            'email' => $email,
            'user_id' => $userId,
            'ip_address' => $ip,
            'user_agent' => null,
            'success' => $success,
            'failure_reason' => $success ? null : 'seeded',
            'created_at' => $createdAt,
        ]);
    }
}

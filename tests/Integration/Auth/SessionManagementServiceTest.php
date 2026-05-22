<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Infrastructure\Auth\Services\SessionManagementService;
use Config\Database;
use Tests\Support\IntegrationTestCase;

/**
 * Pins the persistence contract of SessionManagementService against the real
 * `sessions` table. Each test inserts at least one user (FK target) and then
 * exercises one method's happy path plus the security-relevant edge cases:
 *  - concurrent session limit revokes the oldest (FIFO eviction)
 *  - revokeSession is scoped to the owning user (cross-tenant safety)
 *  - revokeSessionByAccessJti is idempotent when the session is already revoked
 *  - getActiveSessions hides revoked + expired rows
 *  - cleanupExpiredSessions hard-deletes only the rows past expires_at
 */
final class SessionManagementServiceTest extends IntegrationTestCase
{
    private const string IP = '203.0.113.7';
    private const string UA = 'Mozilla/5.0 SessionTest';

    public function test_create_session_inserts_row_with_fingerprint_and_returns_id(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('s1@example.test');

        $id = $service->createSession($userId, 'jti-A1', 'jti-R1', self::IP, self::UA, time() + 3600);

        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('sessions', [
            'id' => $id,
            'user_id' => $userId,
            'access_token_jti' => 'jti-A1',
            'revoked' => 0,
        ]);
        $this->assertNotEmpty($this->fetchSession($id)['device_fingerprint']);
    }

    public function test_create_session_records_null_user_agent_without_error(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('s2@example.test');

        $id = $service->createSession($userId, 'jti-A2', 'jti-R2', self::IP, null, time() + 1800);

        $row = $this->fetchSession($id);
        $this->assertNull($row['user_agent']);
        $this->assertNotEmpty($row['device_fingerprint']);
    }

    public function test_create_session_revokes_oldest_when_limit_reached(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('limit@example.test');

        // 5 sessions = at-the-limit; 6th must revoke the oldest.
        $firstId = $service->createSession($userId, 'A0', 'R0', self::IP, self::UA, time() + 7200);
        for ($i = 1; $i < 5; $i++) {
            $service->createSession($userId, 'A' . $i, 'R' . $i, self::IP, self::UA, time() + 7200);
        }
        $service->createSession($userId, 'A6', 'R6', self::IP, self::UA, time() + 7200);

        $this->assertSame(1, (int) $this->fetchSession($firstId)['revoked']);
    }

    public function test_update_last_activity_only_touches_unrevoked_session(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('act@example.test');
        $id = $service->createSession($userId, 'jti-act', 'jti-act-r', self::IP, self::UA, time() + 3600);
        $before = $this->fetchSession($id)['last_activity_at'];

        sleep(1); // ensure timestamp can advance at second-granularity
        $service->updateLastActivity('jti-act');

        $this->assertNotSame($before, $this->fetchSession($id)['last_activity_at']);
    }

    public function test_update_last_activity_is_noop_for_revoked_session(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('act2@example.test');
        $id = $service->createSession($userId, 'jti-actx', 'jti-actx-r', self::IP, self::UA, time() + 3600);
        $service->revokeSession($id, $userId);
        $afterRevoke = $this->fetchSession($id)['last_activity_at'];

        sleep(1);
        $service->updateLastActivity('jti-actx');

        $this->assertSame($afterRevoke, $this->fetchSession($id)['last_activity_at']);
    }

    public function test_revoke_session_marks_row_revoked(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('rev@example.test');
        $id = $service->createSession($userId, 'jti-rev', 'jti-rev-r', self::IP, self::UA, time() + 3600);

        $service->revokeSession($id, $userId);

        $this->assertSame(1, (int) $this->fetchSession($id)['revoked']);
    }

    public function test_revoke_session_does_nothing_when_user_id_mismatches(): void
    {
        // SECURITY: tenant isolation — user A must not be able to revoke user B's session.
        $service = new SessionManagementService();
        $ownerId = $this->insertUser('owner@example.test');
        $attackerId = $this->insertUser('attacker@example.test');
        $id = $service->createSession($ownerId, 'jti-own', 'jti-own-r', self::IP, self::UA, time() + 3600);

        $service->revokeSession($id, $attackerId);

        $this->assertSame(0, (int) $this->fetchSession($id)['revoked']);
    }

    public function test_revoke_session_by_access_jti_revokes_matching_active_session(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('byjti@example.test');
        $id = $service->createSession($userId, 'jti-byaccess', 'jti-byaccess-r', self::IP, self::UA, time() + 3600);

        $service->revokeSessionByAccessJti('jti-byaccess', $userId);

        $this->assertSame(1, (int) $this->fetchSession($id)['revoked']);
    }

    public function test_revoke_session_by_access_jti_is_idempotent(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('idem@example.test');
        $service->createSession($userId, 'jti-idem', 'jti-idem-r', self::IP, self::UA, time() + 3600);
        $service->revokeSessionByAccessJti('jti-idem', $userId);

        // Second call must not throw and must not flip any new row.
        $service->revokeSessionByAccessJti('jti-idem', $userId);

        $this->assertDatabaseHas('sessions', ['access_token_jti' => 'jti-idem', 'revoked' => 1]);
    }

    public function test_revoke_all_user_sessions_marks_only_active_ones(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('all@example.test');
        $a = $service->createSession($userId, 'a-1', 'a-1r', self::IP, self::UA, time() + 3600);
        $b = $service->createSession($userId, 'a-2', 'a-2r', self::IP, self::UA, time() + 3600);
        // pre-revoke one manually so we can assert it stays revoked (idempotent)
        $service->revokeSession($a, $userId);

        $service->revokeAllUserSessions($userId);

        $this->assertSame(1, (int) $this->fetchSession($a)['revoked']);
        $this->assertSame(1, (int) $this->fetchSession($b)['revoked']);
    }

    public function test_revoke_all_user_sessions_isolates_by_user(): void
    {
        $service = new SessionManagementService();
        $victimId = $this->insertUser('victim@example.test');
        $bystanderId = $this->insertUser('bystander@example.test');
        $victimSession = $service->createSession($victimId, 'v-1', 'v-1r', self::IP, self::UA, time() + 3600);
        $bystanderSession = $service->createSession($bystanderId, 'b-1', 'b-1r', self::IP, self::UA, time() + 3600);

        $service->revokeAllUserSessions($victimId);

        $this->assertSame(1, (int) $this->fetchSession($victimSession)['revoked']);
        $this->assertSame(0, (int) $this->fetchSession($bystanderSession)['revoked']);
    }

    public function test_get_active_sessions_returns_only_unrevoked_and_unexpired(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('active@example.test');
        $active = $service->createSession($userId, 'g-1', 'g-1r', self::IP, self::UA, time() + 3600);
        $revoked = $service->createSession($userId, 'g-2', 'g-2r', self::IP, self::UA, time() + 3600);
        $service->revokeSession($revoked, $userId);
        $this->insertExpiredSession($userId, 'g-3'); // expired but not revoked

        $result = $service->getActiveSessions($userId);

        $ids = array_map(static fn(array $r): int => (int) $r['id'], $result);
        $this->assertSame([$active], $ids);
    }

    public function test_get_active_sessions_returns_empty_for_unknown_user(): void
    {
        $service = new SessionManagementService();
        $this->assertSame([], $service->getActiveSessions(987654));
    }

    public function test_cleanup_expired_sessions_deletes_only_expired_rows(): void
    {
        // NOTE: cleanupExpiredSessions() returns 0 on SQLite even when rows are
        // deleted, because BaseBuilder::delete() returns `true` (bool) on the
        // SQLite driver, and the production code maps "bool result" → 0.
        // Under MySQL the return value would be the affected row count.
        // We pin the durable side effect (rows are actually removed) rather
        // than the driver-dependent return value.
        $service = new SessionManagementService();
        $userId = $this->insertUser('clean@example.test');
        $live = $service->createSession($userId, 'c-1', 'c-1r', self::IP, self::UA, time() + 3600);
        $this->insertExpiredSession($userId, 'c-old-1');
        $this->insertExpiredSession($userId, 'c-old-2');

        $service->cleanupExpiredSessions();

        $this->assertDatabaseHas('sessions', ['id' => $live]);
        $this->assertDatabaseMissing('sessions', ['access_token_jti' => 'c-old-1']);
        $this->assertDatabaseMissing('sessions', ['access_token_jti' => 'c-old-2']);
    }

    public function test_cleanup_expired_sessions_keeps_live_sessions(): void
    {
        $service = new SessionManagementService();
        $userId = $this->insertUser('nothing@example.test');
        $id = $service->createSession($userId, 'live-1', 'live-1r', self::IP, self::UA, time() + 3600);

        $service->cleanupExpiredSessions();

        $this->assertDatabaseHas('sessions', ['id' => $id]);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function insertUser(string $email): int
    {
        $now = date('Y-m-d H:i:s');
        Database::connect()->table('users')->insert([
            'name' => 'Session Test',
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
    private function fetchSession(int $id): array
    {
        $row = Database::connect()->table('sessions')->where('id', $id)->get()->getRowArray();
        $this->assertNotNull($row, "Session #{$id} should exist");

        return $row;
    }

    private function insertExpiredSession(int $userId, string $jti): void
    {
        $past = date('Y-m-d H:i:s', time() - 7200);
        Database::connect()->table('sessions')->insert([
            'user_id' => $userId,
            'access_token_jti' => $jti,
            'refresh_token_jti' => $jti . '-r',
            'ip_address' => self::IP,
            'user_agent' => self::UA,
            'device_fingerprint' => hash('sha256', $jti),
            'last_activity_at' => $past,
            'expires_at' => $past,
            'revoked' => false,
            'created_at' => $past,
            'updated_at' => $past,
        ]);
    }
}

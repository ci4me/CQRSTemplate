<?php

declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Infrastructure\Database\MySQLi\Connection as PinnedMySQLiConnection;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database as DatabaseConfig;

/**
 * Verifies that the MySQL connection envelope (E03) is pinned at
 * connect time AND survives a reconnect.
 *
 * Config-shape assertions (groups carry `sessionVariables`) run on every
 * lane; runtime assertions (the live MySQL session reflects the pins)
 * skip automatically on SQLite — the default `tests` group forced by
 * phpunit.xml.dist. The MySQL CI lane (E01) exercises the non-skipped paths.
 *
 * Reference: `.audit/round3/REMEDIATION-PLAN.md#E03`,
 *            `.audit/round3/REVIEW-ci4.md` finding #3,
 *            `.audit/round3/18-mysql-database.md` F-C1/F-C2/F-C3.
 */
final class ConnectionEnvelopeTest extends CIUnitTestCase
{
    /**
     * Expected pinned values — must match `Config\Database::$default['sessionVariables']`.
     *
     * @var array<string, string>
     */
    private const EXPECTED = [
        'sql_mode'              => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,'
                                 . 'ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                                 . 'NO_ENGINE_SUBSTITUTION',
        'transaction_isolation' => 'READ-COMMITTED',
        'time_zone'             => '+00:00',
        'collation_connection'  => 'utf8mb4_unicode_ci',
    ];

    public function test_default_group_carries_session_variables_array(): void
    {
        $config = new DatabaseConfig();

        $this->assertArrayHasKey('sessionVariables', $config->default);
        $this->assertSame(
            self::EXPECTED['sql_mode'],
            $config->default['sessionVariables']['sql_mode'],
        );
        $this->assertSame(
            self::EXPECTED['transaction_isolation'],
            $config->default['sessionVariables']['transaction_isolation'],
        );
        $this->assertSame(
            self::EXPECTED['time_zone'],
            $config->default['sessionVariables']['time_zone'],
        );
        $this->assertSame(
            self::EXPECTED['collation_connection'],
            $config->default['sessionVariables']['collation_connection'],
        );
    }

    public function test_mysql_ci_group_inherits_the_same_envelope(): void
    {
        $config = new DatabaseConfig();

        $this->assertTrue(
            property_exists($config, 'mysql_ci'),
            'Config\\Database must expose a `mysql_ci` connection group.',
        );
        $this->assertSame(
            $config->default['sessionVariables'],
            $config->mysql_ci['sessionVariables'],
            'mysql_ci group must inherit the same envelope as $default.',
        );
    }

    public function test_tests_group_carries_envelope_for_mysql_lane_inheritance(): void
    {
        $config = new DatabaseConfig();

        // The `tests` group itself runs SQLite by default; the envelope is
        // declared so the MySQL CI lane (which swaps DBDriver via env)
        // inherits the same pins.
        $this->assertArrayHasKey('sessionVariables', $config->tests);
        $this->assertSame(
            $config->default['sessionVariables'],
            $config->tests['sessionVariables'],
            'tests group must carry the same envelope as $default for MySQL-lane inheritance.',
        );
    }

    public function test_default_dbcollat_matches_cookie_migration_collation(): void
    {
        $config = new DatabaseConfig();

        // Cookie migration pins `utf8mb4_unicode_ci` at the column level;
        // the Config-level `DBCollat` must agree to avoid JOIN-time
        // collation mixing (slice 18 F3).
        $this->assertSame('utf8mb4_unicode_ci', $config->default['DBCollat']);
        $this->assertSame('utf8mb4_unicode_ci', $config->mysql_ci['DBCollat']);
    }

    public function test_session_variables_pinned_at_connect(): void
    {
        $db = $this->requireMySqlConnection();

        $this->assertEnvelopeMatches($db);
    }

    public function test_session_variables_pinned_after_reconnect(): void
    {
        $db = $this->requireMySqlConnection();

        // Force a reconnect: BaseConnection::reconnect() closes + re-initializes.
        // Our subclass re-applies the envelope in connect(); this regression
        // assertion guards against future refactors that move the apply call
        // out of connect() (per REVIEW-ci4 finding #3).
        $db->reconnect();

        $this->assertEnvelopeMatches($db);
    }

    /**
     * Resolve the active connection and skip the test if it's not MySQL.
     */
    private function requireMySqlConnection(): BaseConnection
    {
        $db = db_connect();

        if (! $db instanceof BaseConnection) {
            $this->markTestSkipped('Active connection is not a BaseConnection.');
        }

        $driver = $db->DBDriver;
        if (
            $driver !== PinnedMySQLiConnection::class
            && ! str_contains($driver, 'MySQLi')
        ) {
            $this->markTestSkipped(
                'Connection envelope runtime test requires the MySQLi driver; got: ' . $driver,
            );
        }

        return $db;
    }

    /**
     * Asserts the four pinned session variables match expectations.
     */
    private function assertEnvelopeMatches(BaseConnection $db): void
    {
        $row = $db->query(
            'SELECT @@SESSION.sql_mode AS sql_mode, '
            . '@@SESSION.transaction_isolation AS transaction_isolation, '
            . '@@SESSION.time_zone AS time_zone, '
            . '@@SESSION.collation_connection AS collation_connection',
        )->getRowArray();

        $this->assertIsArray($row);

        // sql_mode: MySQL may re-order flags; compare as sets.
        $expectedModes = explode(',', self::EXPECTED['sql_mode']);
        $actualModes   = explode(',', (string) $row['sql_mode']);
        sort($expectedModes);
        sort($actualModes);
        $this->assertSame(
            $expectedModes,
            $actualModes,
            'sql_mode flags must match the pinned set.',
        );

        // transaction_isolation: accept either separator form (some servers
        // normalise `READ-COMMITTED` to `READ COMMITTED` on read-back).
        $actualIso = str_replace(' ', '-', strtoupper((string) $row['transaction_isolation']));
        $this->assertSame(
            self::EXPECTED['transaction_isolation'],
            $actualIso,
            'transaction_isolation must be READ-COMMITTED.',
        );

        $this->assertSame(
            self::EXPECTED['time_zone'],
            $row['time_zone'],
            'time_zone must be UTC (+00:00).',
        );

        $this->assertSame(
            self::EXPECTED['collation_connection'],
            $row['collation_connection'],
            'collation_connection must be utf8mb4_unicode_ci.',
        );
    }
}

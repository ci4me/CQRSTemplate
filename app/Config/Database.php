<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * The default database connection.
     *
     * Connection envelope (see `.audit/round3/REMEDIATION-PLAN.md#E03`).
     * `sessionVariables` is consumed by `App\Infrastructure\Database\MySQLi\Connection`
     * (the FQCN driver bound below) and re-applied on every (re)connect.
     *
     * What each pin buys us:
     *  - `sql_mode` — pins strict semantics so over-length writes ERROR
     *    (otherwise MySQL silently truncates, e.g. the `'unsupported_schema'`
     *    → `unsupported_schem` bug in slice 18 F-O8). `STRICT_TRANS_TABLES`
     *    is paired with `NO_ZERO_DATE`/`NO_ZERO_IN_DATE` (no `'0000-00-00'`),
     *    `ONLY_FULL_GROUP_BY` (no implicit GROUP BY columns),
     *    `ERROR_FOR_DIVISION_BY_ZERO`, and `NO_ENGINE_SUBSTITUTION` (refuse
     *    silent storage-engine fallback).
     *  - `transaction_isolation = READ-COMMITTED` — matches the project's
     *    optimistic-lock-by-affected-rows pattern in CookieRepository
     *    (slice 03 F8) and aligns with Postgres semantics for portability.
     *  - `time_zone = +00:00` — pins UTC at the connection level so
     *    `DATETIME` columns are unambiguous across deploy targets.
     *  - `character_set_connection` / `collation_connection` — belt-and-
     *    braces in case the server's defaults disagree with `charset` /
     *    `DBCollat` below.
     *
     * `DBCollat` is aligned to `utf8mb4_unicode_ci` (matching the column-
     * level collation in `CreateCookiesTable`) — fixes slice 18 F3.
     *
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => 'localhost',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        // FQCN driver: subclass of MySQLi that applies `sessionVariables`
        // on every (re)connect. See app/Infrastructure/Database/MySQLi/.
        'DBDriver'     => 'App\\Infrastructure\\Database\\MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        // Aligned with Cookie migration's column-level collation
        // (utf8mb4_unicode_ci). See REMEDIATION-PLAN.md#E03 / slice 18 F3.
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        // DEVELOPMENT: SSL disabled (certificates not configured)
        // PRODUCTION: Configure SSL certificates and set to array
        'encrypt'      => false,
        'compress'     => false,
        // Belt-and-braces: `strictOn` adds STRICT_ALL_TABLES via
        // MYSQLI_INIT_COMMAND; `sessionVariables` is the authoritative pin.
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        'sessionVariables' => [
            'sql_mode'                 => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,'
                                        . 'ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                                        . 'NO_ENGINE_SUBSTITUTION',
            'transaction_isolation'    => 'READ-COMMITTED',
            'time_zone'                => '+00:00',
            'character_set_connection' => 'utf8mb4',
            'collation_connection'     => 'utf8mb4_unicode_ci',
        ],
    ];

    /**
     * MySQL connection group used by the CI-only lane (see E01 PR #30).
     *
     * Carries the SAME `sessionVariables` envelope as `$default` so that
     * integration tests running under MySQL exercise the same strict
     * semantics production will see. Hostname/database/credentials come
     * from `.env` (`database.mysql_ci.*`).
     *
     * Reference: `.audit/round3/REMEDIATION-PLAN.md#E03`.
     *
     * @var array<string, mixed>
     */
    public array $mysql_ci = [
        'DSN'          => '',
        'hostname'     => '127.0.0.1',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'App\\Infrastructure\\Database\\MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => true,
        'charset'      => 'utf8mb4',
        'DBCollat'     => 'utf8mb4_unicode_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'compress'     => false,
        'strictOn'     => true,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
        'foundRows'    => false,
        'dateFormat'   => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        'sessionVariables' => [
            'sql_mode'                 => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,'
                                        . 'ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                                        . 'NO_ENGINE_SUBSTITUTION',
            'transaction_isolation'    => 'READ-COMMITTED',
            'time_zone'                => '+00:00',
            'character_set_connection' => 'utf8mb4',
            'collation_connection'     => 'utf8mb4_unicode_ci',
        ],
    ];

    //    /**
    //     * Sample database connection for SQLite3.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'database'    => 'database.db',
    //        'DBDriver'    => 'SQLite3',
    //        'DBPrefix'    => '',
    //        'DBDebug'     => true,
    //        'swapPre'     => '',
    //        'failover'    => [],
    //        'foreignKeys' => true,
    //        'busyTimeout' => 1000,
    //        'synchronous' => null,
    //        'dateFormat'  => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for Postgre.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => '',
    //        'hostname'   => 'localhost',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'database'   => 'ci4',
    //        'schema'     => 'public',
    //        'DBDriver'   => 'Postgre',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'utf8',
    //        'swapPre'    => '',
    //        'failover'   => [],
    //        'port'       => 5432,
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for SQLSRV.
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => '',
    //        'hostname'   => 'localhost',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'database'   => 'ci4',
    //        'schema'     => 'dbo',
    //        'DBDriver'   => 'SQLSRV',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'utf8',
    //        'swapPre'    => '',
    //        'encrypt'    => false,
    //        'failover'   => [],
    //        'port'       => 1433,
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    //    /**
    //     * Sample database connection for OCI8.
    //     *
    //     * You may need the following environment variables:
    //     *   NLS_LANG                = 'AMERICAN_AMERICA.UTF8'
    //     *   NLS_DATE_FORMAT         = 'YYYY-MM-DD HH24:MI:SS'
    //     *   NLS_TIMESTAMP_FORMAT    = 'YYYY-MM-DD HH24:MI:SS'
    //     *   NLS_TIMESTAMP_TZ_FORMAT = 'YYYY-MM-DD HH24:MI:SS'
    //     *
    //     * @var array<string, mixed>
    //     */
    //    public array $default = [
    //        'DSN'        => 'localhost:1521/XEPDB1',
    //        'username'   => 'root',
    //        'password'   => 'root',
    //        'DBDriver'   => 'OCI8',
    //        'DBPrefix'   => '',
    //        'pConnect'   => false,
    //        'DBDebug'    => true,
    //        'charset'    => 'AL32UTF8',
    //        'swapPre'    => '',
    //        'failover'   => [],
    //        'dateFormat' => [
    //            'date'     => 'Y-m-d',
    //            'datetime' => 'Y-m-d H:i:s',
    //            'time'     => 'H:i:s',
    //        ],
    //    ];

    /**
     * This database connection is used when running PHPUnit database tests.
     *
     * NOTE: phpunit.xml.dist forces this group to SQLite3 (`:memory:`), so
     * the `sessionVariables` envelope below is dormant by default — the
     * MySQLi driver is what consumes it. When the MySQL CI lane (E01) runs
     * the suite under MySQL it overrides `database.tests.*` via env or
     * switches to the `mysql_ci` group above; both inherit the same pins.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => '127.0.0.1',
        'username'    => '',
        'password'    => '',
        'database'    => ':memory:',
        'DBDriver'    => 'SQLite3',
        'DBPrefix'    => 'db_',  // Needed to ensure we're working correctly with prefixes live. DO NOT REMOVE FOR CI DEVS
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'DBCollat'    => '',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => false,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
        // Carried so that the MySQL CI lane (E01) — which overrides
        // `database.tests.DBDriver` to the FQCN MySQLi via env — inherits
        // the production envelope. Ignored by the SQLite3 driver.
        'sessionVariables' => [
            'sql_mode'                 => 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,'
                                        . 'ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,'
                                        . 'NO_ENGINE_SUBSTITUTION',
            'transaction_isolation'    => 'READ-COMMITTED',
            'time_zone'                => '+00:00',
            'character_set_connection' => 'utf8mb4',
            'collation_connection'     => 'utf8mb4_unicode_ci',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Ensure that we always set the database group to 'tests' if
        // we are currently running an automated test suite, so that
        // we don't overwrite live data on accident.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}

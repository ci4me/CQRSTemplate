<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Utils as BaseMySQLiUtils;

/**
 * Utils for the session-pinned MySQLi driver.
 *
 * Pure delegation to the framework class; required because
 * `Database::initDriver()` resolves utility siblings from the active
 * connection's namespace. See sibling `Connection.php` for the actual
 * envelope behaviour.
 *
 * Reference: `.audit/round3/REMEDIATION-PLAN.md` (E03 section).
 */
final class Utils extends BaseMySQLiUtils
{
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\MySQLi;

use CodeIgniter\Database\MySQLi\Forge as BaseMySQLiForge;

/**
 * Forge for the session-pinned MySQLi driver.
 *
 * Exists only to satisfy CodeIgniter's FQCN-based driver factory
 * (`Database::initDriver()`) which resolves `Forge` / `Utils` siblings
 * from the same namespace as the active `Connection`. All schema
 * behaviour is inherited verbatim from `\CodeIgniter\Database\MySQLi\Forge`.
 *
 * Reference: `.audit/round3/REMEDIATION-PLAN.md` (E03 section).
 */
final class Forge extends BaseMySQLiForge
{
}

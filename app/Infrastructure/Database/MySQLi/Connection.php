<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\MySQLi;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\MySQLi\Connection as BaseMySQLiConnection;
use mysqli;

/**
 * Reconnect-safe MySQLi connection that pins session-level invariants.
 *
 * Why this subclass exists:
 *  - CodeIgniter 4.6's stock `MySQLi\Connection` only honours `strictOn`
 *    via `MYSQLI_INIT_COMMAND`; it has no `sessionVariables` config key.
 *  - `BaseConnection::reconnect()` re-runs `connect()`, so applying
 *    session variables *here* (as opposed to a one-shot Services hook)
 *    guarantees the envelope survives idle-timeout reconnects too
 *    (see `.audit/round3/REVIEW-ci4.md` finding #3).
 *
 * Reads `sessionVariables` from the connection config (declared as a
 * public property below so `BaseConnection::__construct()` copies the
 * array onto the instance via its `property_exists()` filter) and emits
 * a single multi-statement `SET SESSION` immediately after the
 * underlying `mysqli` handshake succeeds.
 *
 * Reference: `.audit/round3/REMEDIATION-PLAN.md` (E03 section).
 */
final class Connection extends BaseMySQLiConnection
{
    /**
     * Session-level pins applied on every (re)connect.
     *
     * Populated by `BaseConnection::__construct()` from the config array
     * via `property_exists()` reflection. Each entry becomes a
     * `SET SESSION <key> = <value>` clause.
     *
     * @var array<string, scalar>
     */
    public array $sessionVariables = [];

    /**
     * Connect and immediately pin session invariants.
     *
     * @param bool $persistent Whether to open a persistent connection
     *                         (forwarded to the parent driver).
     * @return false|mysqli Raw mysqli handle on success, false on failure
     *                      with `DBDebug=false`.
     * @throws DatabaseException When the connect or envelope-apply fails
     *                           with `DBDebug=true`.
     */
    public function connect(bool $persistent = false): false|mysqli
    {
        $handle = parent::connect($persistent);

        if ($handle instanceof mysqli && $this->sessionVariables !== []) {
            $this->applySessionVariables($handle);
        }

        return $handle;
    }

    /**
     * Apply the pinned session variables in a single statement.
     *
     * @param mysqli $handle The freshly-opened mysqli handle.
     * @return void
     * @throws DatabaseException When the SET SESSION emission fails and
     *                           DBDebug is enabled.
     */
    private function applySessionVariables(mysqli $handle): void
    {
        $clauses = [];

        foreach ($this->sessionVariables as $name => $value) {
            $clauses[] = $this->buildClause($handle, $name, $value);
        }

        $sql = 'SET SESSION ' . implode(', ', $clauses);

        if ($handle->real_query($sql)) {
            return;
        }

        $message = 'Unable to apply MySQL session envelope: ' . $handle->error;
        log_message('error', $message);

        if ($this->DBDebug) {
            throw new DatabaseException($message);
        }
    }

    /**
     * Build a single `<name> = <quoted-value>` clause.
     *
     * @param mysqli $handle Open mysqli handle (used for escaping).
     * @param string $name   Session-variable name (already trusted, taken from config).
     * @param scalar $value  Session-variable value (escaped before quoting).
     * @return string
     */
    private function buildClause(mysqli $handle, string $name, mixed $value): string
    {
        $quoted = "'" . $handle->real_escape_string((string) $value) . "'";

        return $name . ' = ' . $quoted;
    }
}

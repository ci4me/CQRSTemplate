<?php

declare(strict_types=1);

namespace App\Domain\User\Ports;

/**
 * Domain port for the password-history repository.
 *
 * The User domain consults this port to enforce the "no recently-used
 * password" rule on password change / reset. The Infrastructure adapter
 * persists hashed entries in the `password_history` table and prunes them
 * to a rolling window.
 *
 * The methods declared here are exactly the operations the domain handlers
 * actually call. Maintenance / pruning details live on the concrete class
 * and don't need to leak into the port.
 *
 * @package App\Domain\User\Ports
 */
interface PasswordHistoryRepositoryInterface
{
    /**
     * Persist a hashed password as the most recent entry in the user's
     * history, evicting older entries beyond the configured retention.
     *
     * @param int    $userId       The owning user.
     * @param string $passwordHash The Argon2id (or compatible) hash.
     * @return int The insert id of the stored history row.
     */
    public function store(int $userId, string $passwordHash): int;

    /**
     * True when an EXACT hash already exists in the user's history.
     *
     * Used by paths that already hold the hash (e.g. ResetPassword) and
     * want a constant-time identity check.
     *
     * @param int    $userId       The owning user.
     * @param string $passwordHash The candidate hash.
     * @return bool True when the hash is present in the rolling window.
     */
    public function containsHash(int $userId, string $passwordHash): bool;

    /**
     * True when a PLAINTEXT password is verifiable against any hash in
     * the user's history (because each hash has its own salt, this can't
     * be done with hash comparison alone).
     *
     * @param int    $userId            The owning user.
     * @param string $plaintextPassword The candidate plaintext password.
     * @return bool True when the password matches a history entry.
     */
    public function containsPassword(int $userId, string $plaintextPassword): bool;
}

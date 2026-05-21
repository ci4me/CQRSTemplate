<?php

declare(strict_types=1);

namespace App\Domain\User\Repositories;

use CodeIgniter\Database\BaseConnection;

/**
 * Password History Repository.
 *
 * Manages password history for users to prevent password reuse.
 * Maintains last N password hashes per user for security policy enforcement.
 *
 * SECURITY:
 * - Prevents password reuse (common compliance requirement)
 * - Stores only hashed passwords (never plaintext)
 * - Automatically prunes old history to maintain performance
 * - Constant-time containsHash() check for timing attack mitigation
 *
 * NOTE: Not final to allow mocking in unit tests (pragmatic exception to "final by default" rule)
 */
readonly class PasswordHistoryRepository
{
    private const int MAX_HISTORY_COUNT = 5;

    /**
     * @param BaseConnection<object|resource|false, object|resource|false> $db
     */
    public function __construct(
        private BaseConnection $db
    ) {
    }

    /**
     * Store password hash in history.
     *
     * Automatically prunes old entries to maintain MAX_HISTORY_COUNT.
     *
     * @param int    $userId       User ID
     * @param string $passwordHash Argon2ID hashed password
     * @return int Insert ID
     */
    public function store(int $userId, string $passwordHash): int
    {
        // Insert new password hash
        $this->db->table('password_history')->insert([
            'user_id' => $userId,
            'password_hash' => $passwordHash,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $insertId = (int) $this->db->insertID();

        // Prune old entries (keep only last N)
        $this->pruneOldEntries($userId);

        return $insertId;
    }

    /**
     * Get last N password hashes for user.
     *
     * Returns most recent hashes in descending order (newest first).
     *
     * @param int $userId User ID
     * @param int $count  Number of hashes to retrieve (default: MAX_HISTORY_COUNT)
     * @return array<string> Array of password hashes
     */
    public function getLastNHashes(int $userId, int $count = self::MAX_HISTORY_COUNT): array
    {
        $query = $this->db->table('password_history')
            ->select('password_hash')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($count)
            ->get();

        if ($query === false) {
            return [];
        }
        $results = $query->getResultArray();

        return array_map(
            static fn(array $row): string => (string) $row['password_hash'],
            $results
        );
    }

    /**
     * Check if password hash exists in user's history.
     *
     * Uses constant-time comparison to prevent timing attacks.
     *
     * @param int    $userId       User ID
     * @param string $passwordHash Password hash to check
     * @return bool True if hash found in history
     */
    public function containsHash(int $userId, string $passwordHash): bool
    {
        $historyHashes = $this->getLastNHashes($userId);

        $found = false;

        // Use constant-time comparison to prevent timing attacks
        foreach ($historyHashes as $historicHash) {
            if (!hash_equals($historicHash, $passwordHash)) {
                continue;
            }

            $found = true;
        }

        return $found;
    }

    /**
     * Check if plaintext password matches any hash in user's history.
     *
     * Uses password_verify() for proper Argon2id comparison since
     * each hash has a unique salt, making direct hash comparison impossible.
     *
     * @param int    $userId            User ID
     * @param string $plaintextPassword Plaintext password to check
     * @return bool True if password found in history
     */
    public function containsPassword(int $userId, string $plaintextPassword): bool
    {
        $historyHashes = $this->getLastNHashes($userId);

        $found = false;
        foreach ($historyHashes as $historicHash) {
            if (!password_verify($plaintextPassword, $historicHash)) {
                continue;
            }

            $found = true;
        }

        return $found;
    }

    /**
     * Prune old password history entries.
     *
     * Keeps only the last MAX_HISTORY_COUNT entries per user.
     *
     * @param int $userId User ID
     * @return void
     */
    private function pruneOldEntries(int $userId): void
    {
        // Get IDs of entries to keep (last N)
        $result = $this->db->table('password_history')
            ->select('id')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit(self::MAX_HISTORY_COUNT)
            ->get();
        if ($result === false) {
            return;
        }
        $keepIds = $result->getResultArray();

        if (count($keepIds) === 0) {
            return;
        }

        $keepIdList = array_map(
            static fn(array $row): int => (int) $row['id'],
            $keepIds
        );

        // Delete old entries (not in keep list)
        $this->db->table('password_history')
            ->where('user_id', $userId)
            ->whereNotIn('id', $keepIdList)
            ->delete();
    }

    /**
     * Delete all password history for user.
     *
     * Used when user account is deleted.
     *
     * @param int $userId User ID
     * @return int Number of deleted rows
     */
    public function deleteByUserId(int $userId): int
    {
        $this->db->table('password_history')
            ->where('user_id', $userId)
            ->delete();

        return $this->db->affectedRows();
    }

    /**
     * Get password history count for user.
     *
     * @param int $userId User ID
     * @return int Number of password history entries
     */
    public function countByUserId(int $userId): int
    {
        return (int) $this->db->table('password_history')
            ->where('user_id', $userId)
            ->countAllResults();
    }
}

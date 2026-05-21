<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth\Services;

use App\Domain\User\Ports\TokenBlacklistInterface;
use App\Infrastructure\Logging\LoggerFactory;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;

/**
 * Database-backed token blacklist (production default).
 *
 * The cache-backed {@see TokenBlacklistService} is suitable for single-node
 * dev setups but in production a logged-out JWT MUST stay revoked across
 * web nodes and restarts. This implementation stores SHA-256 hashes of
 * blacklisted tokens in the `token_blacklist` table (created by
 * 2025_10_27_104100_CreateTokenBlacklistTable) so the rejection survives:
 *   - any web server crash / restart
 *   - rolling deploys
 *   - cache wipes / FileHandler eviction
 *
 * Each row carries an `expires_at` derived from the refresh-token lifetime;
 * a periodic spark task can prune rows where expires_at < NOW().
 *
 * The class is intentionally NOT final so PHPUnit can double it.
 */
class DatabaseTokenBlacklistService implements TokenBlacklistInterface
{
    private const string TABLE = 'token_blacklist';
    private const int DEFAULT_LIFETIME_SECONDS = 2_592_000; // 30 days

    /** @var LoggerInterface */
    private readonly LoggerInterface $logger;

    /**
     * @param BaseConnection<object|resource|false, object|resource|false>|null $db
     * @param LoggerInterface|null                                              $logger
     */
    public function __construct(
        private readonly ?BaseConnection $db = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? LoggerFactory::create('auth.token.blacklist.db');
    }

    /**
     * blacklist.
     *
     * @param string $token
     * @return void
     */
    public function blacklist(string $token): void
    {
        $hash = $this->hash($token);
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', self::DEFAULT_LIFETIME_SECONDS));

        $connection = $this->connection();
        try {
            $connection->table(self::TABLE)->insert([
                'jti' => $hash,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Duplicate (already blacklisted) is fine and idempotent. Anything
            // else is logged so monitoring can pick up persistent backend
            // outages — but we must NOT raise an exception that would let
            // the logout endpoint succeed while the token stays valid.
            if (!$this->isDuplicate($e)) {
                $this->logger->error('Token blacklist insert failed', [
                    'operation' => 'blacklist',
                    'exception' => $e->getMessage(),
                ]);
                throw $e;
            }
        }
    }

    /**
     * isBlacklisted.
     *
     * @param string $token
     * @return bool
     */
    public function isBlacklisted(string $token): bool
    {
        $hash = $this->hash($token);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $count = $this->connection()
            ->table(self::TABLE)
            ->where('jti', $hash)
            ->where('expires_at >=', $now)
            ->countAllResults();

        return $count > 0;
    }

    /**
     * Drop rows past their `expires_at`. Intended to be called by a
     * spark task on a schedule (e.g. once an hour).
     *
     * @return int
     */
    public function purgeExpired(): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $connection = $this->connection();
        $connection->table(self::TABLE)
            ->where('expires_at <', $now)
            ->delete();

        return $connection->affectedRows();
    }

    /**
     * hash.
     *
     * @param string $token
     * @return string
     */
    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * isDuplicate.
     *
     * @param \Throwable $e
     * @return bool
     */
    private function isDuplicate(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique')
            || str_contains($message, 'constraint failed');
    }

    /**
     * @return BaseConnection<object|resource|false, object|resource|false>
     */
    private function connection(): BaseConnection
    {
        return $this->db ?? Database::connect();
    }
}

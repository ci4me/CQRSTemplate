<?php

declare(strict_types=1);

namespace App\Infrastructure\Bus\Middleware;

use CodeIgniter\Database\BaseConnection;

/**
 * Wraps command handler execution in a database transaction.
 *
 * Automatically commits on success and rolls back on failure.
 *
 * @package App\Infrastructure\Bus\Middleware
 */
final class TransactionMiddleware implements BusMiddlewareInterface
{
    /** @phpstan-ignore missingType.generics */
    public function __construct(private BaseConnection $db)
    {
    }

    public function handle(object $message, callable $next): mixed
    {
        $this->db->transStart();

        try {
            $result = $next($message);
            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                throw new \RuntimeException('Transaction failed');
            }

            return $result;
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }
}

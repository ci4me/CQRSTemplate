<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Handler that brings a soft-deleted cookie back from the trash.
 *
 * This is the only Cookie handler that calls
 * {@see CookieRepositoryInterface::findByIdWithTrashed()} instead of
 * {@see CookieRepositoryInterface::findById()}, so it can see rows whose
 * `deleted_at` is set.
 *
 * Event flow (round-4 R1): {@see \App\Domain\Cookie\Entities\Cookie::restore()}
 * clears `deletedAt` and raises CookieRestoredEvent (throwing a
 * COOKIE_STATE_NOT_DELETED business-rule violation when the row is still
 * alive); the repository persists the un-delete with an optimistic-locking
 * guard and drains the event outbox-first in the same transaction.
 *
 * Failure modes: {@see DomainException::notFound()} when the id has no row
 * at all; businessRuleViolation(COOKIE_STATE_NOT_DELETED) when the row is
 * still alive; concurrentModification when a parallel writer touched the
 * row between load and UPDATE.
 *
 * @package App\Domain\Cookie\Commands\RestoreCookie
 */
final readonly class RestoreCookieHandler
{
    /**
     * Create a new RestoreCookieHandler.
     *
     * @param CookieRepositoryInterface $repository For persistence operations
     * @param LoggerInterface           $logger     For logging command execution (channel: cookie.command.restore)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Bring a soft-deleted cookie back to life.
     *
     * Validates the target row exists, delegates the "is it actually
     * deleted?" invariant to the aggregate, and persists via the repository
     * (which drains CookieRestoredEvent in the same transaction).
     *
     * @param RestoreCookieCommand $command The restore command
     * @throws DomainException When the id is unknown (notFound), the row is not
     */
    public function handle(RestoreCookieCommand $command): void
    {
        $startTime = hrtime(true);

        $this->logger->info('Restoring cookie', [
            'domain' => 'Cookie',
            'command' => 'RestoreCookieCommand',
            'cookieId' => $command->cookieId,
        ]);

        try {
            $cookie = $this->repository->findByIdWithTrashed($command->cookieId);

            if ($cookie === null) {
                throw DomainException::notFound('Cookie', (string) $command->cookieId, ErrorCodes::COOKIE_NOT_FOUND);
            }

            // The aggregate enforces the "must currently be deleted"
            // invariant and raises CookieRestoredEvent.
            $cookie->restore($command->restoredBy);
            $this->repository->restore($cookie, $command->restoredBy);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->logger->info('Cookie restored successfully', [
                'domain' => 'Cookie',
                'command' => 'RestoreCookieCommand',
                'cookieId' => $command->cookieId,
                'duration_ms' => round($durationMs, 2),
            ]);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->logger->error('Failed to restore cookie', [
                'domain' => 'Cookie',
                'command' => 'RestoreCookieCommand',
                'error_code' => $this->determineErrorCode($e),
                'exception' => $e->getMessage(),
                'exceptionClass' => $e::class,
                'cookieId' => $command->cookieId,
                'duration_ms' => round($durationMs, 2),
            ]);

            throw $e;
        }
    }

    /**
     * Pick the most specific ErrorCodes constant for a failed restore.
     *
     * Prefers the exception's own getErrorCode() when present; falls back to
     * COOKIE_REPOSITORY_QUERY_FAILED for raw infrastructure errors so the
     * structured log line always carries a numeric error_code field.
     */
    private function determineErrorCode(\Throwable $e): int
    {
        if ($e instanceof ValidationException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        if ($e instanceof DomainException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        return ErrorCodes::COOKIE_REPOSITORY_QUERY_FAILED;
    }
}

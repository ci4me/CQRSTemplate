<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler for DeleteCookieCommand.
 *
 * Responsibilities:
 * 1. Verify cookie exists
 * 2. Mark the aggregate deleted (raises CookieDeletedEvent with the final snapshot)
 * 3. Persist the soft delete via repository
 *
 * Event flow (round-4 R1): {@see \App\Domain\Cookie\Entities\Cookie::markDeleted()}
 * snapshots the final state and raises the event; the repository persists the
 * flip and drains the event outbox-first in the same transaction. Handlers
 * never construct or dispatch events.
 *
 * Business Rules:
 * - Cookie must exist to be deleted
 * - Deletion is SOFT (sets deleted_at timestamp)
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 */
final readonly class DeleteCookieHandler implements CommandHandlerInterface
{
    /**
     * Create a new DeleteCookieHandler.
     *
     * @param CookieRepositoryInterface $repository For persistence operations
     * @param LoggerInterface           $logger     For logging command execution (channel: cookie.command.delete)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the DeleteCookieCommand.
     *
     * @param DeleteCookieCommand $command The delete command
     * @throws DomainException If cookie not found
     */
    public function handle(DeleteCookieCommand $command): void
    {
        $startTime = hrtime(true);

        $this->logger->info('Deleting cookie', [
            'domain' => 'Cookie',
            'command' => 'DeleteCookieCommand',
            'cookieId' => $command->id,
        ]);

        try {
            $cookie = $this->repository->findById($command->id);

            if ($cookie === null) {
                throw DomainException::notFound('Cookie', $command->id, ErrorCodes::COOKIE_NOT_FOUND);
            }

            $cookieName = $cookie->getName()->getValue();

            $this->logger->info('Cookie found, performing soft delete', [
                'domain' => 'Cookie',
                'command' => 'DeleteCookieCommand',
                'cookieId' => $command->id,
                'cookieName' => $cookieName,
            ]);

            // The aggregate snapshots its final state and raises
            // CookieDeletedEvent; the repository persists the flip and
            // drains the event in the same transaction.
            $cookie->markDeleted($command->deletedBy);
            $this->repository->delete($cookie, $command->deletedBy);

            $durationMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->logger->info('Cookie deleted successfully', [
                'domain' => 'Cookie',
                'command' => 'DeleteCookieCommand',
                'cookieId' => $command->id,
                'soft_delete_confirmed' => true,
                'duration_ms' => round($durationMs, 2),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete cookie', [
                'domain' => 'Cookie',
                'command' => 'DeleteCookieCommand',
                'error_code' => $e instanceof DomainException && $e->getErrorCode() !== 0
                    ? $e->getErrorCode()
                    : ErrorCodes::COOKIE_REPOSITORY_DELETE_FAILED,
                'exception' => $e->getMessage(),
                'exceptionClass' => $e::class,
                'cookieId' => $command->id,
            ]);

            throw $e;
        }
    }
}

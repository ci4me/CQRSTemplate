<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Handler for DeleteCookieCommand.
 *
 * Responsibilities:
 * 1. Verify cookie exists
 * 2. Perform soft delete via repository
 * 3. Dispatch domain event
 *
 * Business Rules:
 * - Cookie must exist to be deleted
 * - Deletion is SOFT (sets deleted_at timestamp)
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 */
final readonly class DeleteCookieHandler
{
    /**
     * Create a new DeleteCookieHandler.
     *
     * @param CookieRepositoryInterface $repository For persistence operations
     * @param EventDispatcher $eventDispatcher For dispatching domain events
     * @param LoggerInterface $logger For logging command execution (channel: cookie.command.delete)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcher $eventDispatcher,
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
            // Load existing cookie to get its name for the event
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

            // Perform soft delete
            $this->repository->delete($command->id);

            // Dispatch domain event
            $this->eventDispatcher->dispatch(new CookieDeletedEvent(
                cookieId: $command->id,
                cookieName: $cookieName
            ));

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

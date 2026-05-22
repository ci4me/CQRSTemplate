<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler for DeleteCookieCommand.
 *
 * Post-E07, the handler delegates the lifecycle transition (deletedAt
 * flip + CookieDeletedEvent envelope construction) to the entity. The
 * handler's job is now:
 *   1. Verify the cookie exists.
 *   2. Ask the entity to soft-delete itself.
 *   3. Persist the new state.
 *   4. Drain the events the entity raised and hand them to the dispatcher.
 *
 * E08 will collapse step 4 into AbstractCommandHandler::dispatchPulledEvents.
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 * @implements CommandHandlerInterface<DeleteCookieCommand, void>
 */
final readonly class DeleteCookieHandler implements CommandHandlerInterface
{
    /**
     * @param CookieRepositoryInterface $repository      For persistence operations.
     * @param EventDispatcherInterface  $eventDispatcher For draining the entity's event bag.
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.delete).
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @throws DomainException If the cookie is not found.
     */
    public function handle(object $command): void
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

            // E07: entity owns the lifecycle transition + event envelope.
            $actorId = $command->deletedBy->isSystem() ? null : $command->deletedBy->id;
            $cookie->softDelete($actorId);

            $this->repository->delete($command->id, $command->deletedBy);

            // Drain the bag the entity populated. E08 hoists this to the
            // abstract handler base; for now each handler drains its own.
            foreach ($cookie->pullEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }

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

<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * RestoreCookieHandler.
 *
 * Post-E07, the entity owns the restore transition (clears deletedAt +
 * raises CookieRestoredEvent). The handler loads the soft-deleted row,
 * asks the entity to restore, calls the repository to persist the new
 * state, and drains the event bag the entity populated. E08 will
 * unify the handler base + rename `$cookieId` to `$id` for parity with
 * the other commands.
 *
 * @implements CommandHandlerInterface<RestoreCookieCommand, void>
 */
final readonly class RestoreCookieHandler implements CommandHandlerInterface
{
    /**
     * @param CookieRepositoryInterface $repository      For find + persist.
     * @param EventDispatcherInterface  $eventDispatcher For draining the entity's event bag.
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.restore).
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @throws DomainException     When the cookie is missing or is not actually deleted.
     * @throws \RuntimeException   When the SQL restore fails.
     */
    #[\Override]
    public function handle(object $command): void
    {

        $cookie = $this->repository->findByIdWithTrashed($command->cookieId);
        if ($cookie === null) {
            throw DomainException::notFound('Cookie', (string) $command->cookieId, ErrorCodes::COOKIE_NOT_FOUND);
        }

        // E07: entity gate replaces the handler's previous isDeleted()
        // re-check. Cookie::restore() throws DomainException with the
        // dedicated COOKIE_STATE_NOT_DELETED code when there's nothing
        // to restore — keeping the precondition single-sourced on the
        // aggregate.
        $actorId = $command->restoredBy->isSystem() ? null : $command->restoredBy->id;
        $cookie->restore($actorId);

        $restored = $this->repository->restore($command->cookieId, $command->restoredBy);
        if (! $restored) {
            $this->logger->error('Cookie restore failed', [
                'domain' => 'Cookie',
                'command' => 'RestoreCookieCommand',
                'cookie_id' => $command->cookieId,
            ]);
            throw new \RuntimeException(
                sprintf('Failed to restore cookie #%d', $command->cookieId)
            );
        }

        $this->logger->info('Cookie restored', [
            'domain' => 'Cookie',
            'command' => 'RestoreCookieCommand',
            'cookie_id' => $command->cookieId,
            'restored_by' => $command->restoredBy->id,
        ]);

        foreach ($cookie->pullEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}

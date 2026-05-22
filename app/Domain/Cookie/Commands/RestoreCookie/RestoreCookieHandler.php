<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler that brings a soft-deleted cookie back from the trash.
 *
 * This is the only Cookie handler that:
 *  - calls {@see CookieRepositoryInterface::findByIdWithTrashed()} instead of
 *    {@see CookieRepositoryInterface::findById()}, so it can see rows whose
 *    `deleted_at` is set;
 *  - performs the restore via the repository's dedicated `restore()` builder
 *    UPDATE rather than the standard `save()` path (save() assumes a live row);
 *  - dispatches {@see CookieRestoredEvent} manually because the entity does
 *    not yet expose a `restore()` lifecycle method.
 *
 * Throws {@see DomainException::notFound()} when the id has no row at all;
 * {@see DomainException::businessRuleViolation()} when the row exists but is
 * still alive (so "restore" is meaningless); and \RuntimeException when the
 * builder UPDATE returns false (would indicate a torn DB and is intentionally
 * NOT a domain exception). Slice 03 tracks the eventual conversion of that
 * \RuntimeException to a domain-level exception.
 */
final readonly class RestoreCookieHandler
{
    /**
     * Inject the write repository, event dispatcher, and PSR-3 logger.
     *
     * @param CookieRepositoryInterface $repository      Write-side port; used for findByIdWithTrashed + restore.
     * @param EventDispatcherInterface  $eventDispatcher Dispatches CookieRestoredEvent on success (manual dispatch — see class docblock).
     * @param LoggerInterface           $logger          Structured audit log for restore success / failure.
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Bring a soft-deleted cookie back to life and dispatch CookieRestoredEvent.
     *
     * Validates the target row exists AND is currently soft-deleted, performs
     * the restore via the repository, then dispatches the event. See the
     * class docblock for the failure modes.
     *
     * @throws DomainException   When the id is unknown (notFound) or the row is not deleted (businessRuleViolation).
     * @throws \RuntimeException When the UPDATE returns false; indicates a torn DB write.
     */
    public function handle(RestoreCookieCommand $command): void
    {
        $cookie = $this->repository->findByIdWithTrashed($command->cookieId);

        if ($cookie === null) {
            throw DomainException::notFound('Cookie', (string) $command->cookieId, ErrorCodes::COOKIE_NOT_FOUND);
        }

        if (!$cookie->isDeleted()) {
            throw DomainException::businessRuleViolation(
                'Cookie is not deleted; nothing to restore.',
                (string) $command->cookieId,
                ErrorCodes::COOKIE_NOT_FOUND
            );
        }

        $restored = $this->repository->restore($command->cookieId, $command->restoredBy);

        if (!$restored) {
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

        $this->eventDispatcher->dispatch(
            new CookieRestoredEvent(
                cookieId: $command->cookieId,
                restoredBy: $command->restoredBy->id,
                restoredAt: (new \DateTimeImmutable())->format('c')
            )
        );
    }
}

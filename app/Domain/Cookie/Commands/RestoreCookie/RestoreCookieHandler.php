<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * RestoreCookieHandler.
 *
 * @implements CommandHandlerInterface<RestoreCookieCommand, void>
 */
final readonly class RestoreCookieHandler implements CommandHandlerInterface
{
    /**
     * __construct.
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * handle.
     *
     * @param RestoreCookieCommand $command
     * @throws DomainException
     * @throws \RuntimeException
     */
    public function handle(object $command): void
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

        // The envelope's `occurredAt` supersedes the legacy `restoredAt`
        // string field (slice 05/F3).
        $this->eventDispatcher->dispatch(
            new CookieRestoredEvent(
                eventId: AbstractDomainEvent::newId(),
                occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                actorId: $command->restoredBy->isSystem() ? null : $command->restoredBy->id,
                cookieId: $command->cookieId,
            )
        );
    }
}

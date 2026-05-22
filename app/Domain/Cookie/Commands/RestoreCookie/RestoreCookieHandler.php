<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\RestoreCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\AbstractCommandHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see RestoreCookieCommand}.
 *
 * E08 brings this handler to PARITY with DeleteCookieHandler (closes 03/F2):
 *  - Renamed command field `$cookieId` → `$id`.
 *  - Throws {@see DomainException} (not `\RuntimeException`) on restore
 *    failure, with the dedicated {@see ErrorCodes::COOKIE_RESTORE_FAILED}
 *    code so observability + API mappers can react consistently.
 *  - camelCase log keys (`cookieId`, `restoredBy`) matching the other
 *    three handlers — closes 03/F12.
 *  - Extends {@see AbstractCommandHandler} so the timing + log shape +
 *    error-code resolution flow through the shared template.
 *  - Single event-bag drain via parent's {@see postCommit()} — closes 03/F1.
 *
 * The entity guards the "already active" precondition via
 * `Cookie::restore()` (throws DomainException with COOKIE_STATE_NOT_DELETED),
 * which is why the handler does NOT redundantly check `isDeleted()` —
 * the entity is the single source of truth for that invariant (closes 03/F10).
 *
 * @implements CommandHandlerInterface<RestoreCookieCommand, void>
 */
final class RestoreCookieHandler extends AbstractCommandHandler implements CommandHandlerInterface
{
    /**
     * Slot used to pass the mutated entity from doHandle() to postCommit().
     */
    private ?Cookie $pendingAggregate = null;

    /**
     * @param CookieRepositoryInterface $repository      Persistence port.
     * @param EventDispatcherInterface  $eventDispatcher Drained in postCommit().
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.restore).
     * @param ClockInterface            $clock           Monotonic time source.
     */
    public function __construct(
        private readonly CookieRepositoryInterface $repository,
        private readonly EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        ClockInterface $clock
    ) {
        parent::__construct($logger, $clock);
    }

    /**
     * @param RestoreCookieCommand $command The restore command DTO.
     * @throws DomainException When the cookie is missing, is not deleted,
     *                         or the SQL UPDATE returns false.
     */
    protected function doHandle(object $command): void
    {
        $cookie = $this->repository->findByIdWithTrashed($command->id);
        if ($cookie === null) {
            throw DomainException::notFound('Cookie', $command->id, ErrorCodes::COOKIE_NOT_FOUND);
        }
        $actorId = $command->restoredBy->isSystem() ? null : $command->restoredBy->id;
        $cookie->restore($actorId);
        $restored = $this->repository->restore($command->id, $command->restoredBy);
        if (!$restored) {
            throw DomainException::businessRuleViolation(
                'Cookie restore must persist',
                sprintf('Failed to restore cookie #%d', $command->id),
                ErrorCodes::COOKIE_RESTORE_FAILED
            );
        }
        $this->pendingAggregate = $cookie;
    }

    protected function postCommit(object $command, mixed $result): void
    {
        unset($command, $result);
        if ($this->pendingAggregate === null) {
            return;
        }
        $this->dispatchPulledEvents($this->pendingAggregate->pullEvents(), $this->eventDispatcher);
        $this->pendingAggregate = null;
    }

    protected function getDomain(): string
    {
        return 'Cookie';
    }

    protected function commandClass(): string
    {
        return RestoreCookieCommand::class;
    }

    protected function defaultErrorCode(): int
    {
        return ErrorCodes::COOKIE_RESTORE_FAILED;
    }
}

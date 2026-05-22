<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\DeleteCookie;

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
 * Handler for {@see DeleteCookieCommand}.
 *
 * Post-E07/E08:
 *   1. Verify the cookie exists.
 *   2. Ask the entity to soft-delete itself (entity raises CookieDeletedEvent).
 *   3. Persist the new state.
 *   4. Parent's {@see postCommit()} drains the bag — single dispatch site
 *      (closes 03/F1).
 *
 * @package App\Domain\Cookie\Commands\DeleteCookie
 * @implements CommandHandlerInterface<DeleteCookieCommand, void>
 */
final class DeleteCookieHandler extends AbstractCommandHandler implements CommandHandlerInterface
{
    /**
     * Slot used to pass the mutated entity from doHandle() to postCommit().
     */
    private ?Cookie $pendingAggregate = null;

    /**
     * @param CookieRepositoryInterface $repository      Persistence port.
     * @param EventDispatcherInterface  $eventDispatcher Drained in postCommit().
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.delete).
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
     * @param DeleteCookieCommand $command The delete command DTO.
     * @throws DomainException When the cookie is not found.
     */
    protected function doHandle(object $command): void
    {
        $cookie = $this->repository->findById($command->id);
        if ($cookie === null) {
            throw DomainException::notFound('Cookie', $command->id, ErrorCodes::COOKIE_NOT_FOUND);
        }
        $actorId = $command->deletedBy->isSystem() ? null : $command->deletedBy->id;
        $cookie->softDelete($actorId);
        $this->repository->delete($command->id, $command->deletedBy);
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
        return DeleteCookieCommand::class;
    }

    protected function defaultErrorCode(): int
    {
        return ErrorCodes::COOKIE_REPOSITORY_DELETE_FAILED;
    }
}

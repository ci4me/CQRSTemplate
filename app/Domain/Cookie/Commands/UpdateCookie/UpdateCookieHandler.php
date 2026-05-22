<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Bus\AbstractCommandHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see UpdateCookieCommand}.
 *
 * Loads the entity, runs an optimistic-locking pre-flight (the command's
 * `expectedVersion` is REQUIRED post-E08 — closes 03/F9), then asks the
 * entity to mutate itself. The entity raises {@see \App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent}
 * (and any auxiliary lifecycle events such as activate/deactivate) into
 * its bag; {@see postCommit()} drains the bag via the parent's helper —
 * single dispatch site (closes 03/F1).
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 * @implements CommandHandlerInterface<UpdateCookieCommand, void>
 */
final class UpdateCookieHandler extends AbstractCommandHandler implements CommandHandlerInterface
{
    /**
     * Slot used to pass the mutated entity from doHandle() to postCommit().
     *
     * Stored as instance state so the parent template can drain the
     * aggregate's event bag without changing the contract of doHandle()
     * (which returns void). NULL between handle() invocations.
     */
    private ?Cookie $pendingAggregate = null;

    /**
     * @param CookieRepositoryInterface $repository      Persistence port.
     * @param EventDispatcherInterface  $eventDispatcher Drained in postCommit().
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.update).
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
     * @param UpdateCookieCommand $command The update command DTO.
     * @throws DomainException When the cookie is missing, the optimistic lock
     *                         loses the race, or the new name collides.
     */
    protected function doHandle(object $command): void
    {
        $cookie = $this->loadCookie($command->id);
        $this->assertVersionMatches($cookie, $command);
        $name = CookieName::fromString($command->name);
        $price = CookiePrice::fromString($command->price);
        $this->assertNameAvailable($cookie, $name, $command->id);
        $cookie->update(
            name: $name,
            description: $command->description,
            price: $price,
            stock: $command->stock,
            isActive: $command->isActive,
        );
        $this->repository->save($cookie, $command->updatedBy);
        $this->pendingAggregate = $cookie;
    }

    /**
     * @throws DomainException When the row is missing.
     */
    private function loadCookie(int $id): Cookie
    {
        $cookie = $this->repository->findById($id);
        if ($cookie === null) {
            throw DomainException::notFound('Cookie', $id, ErrorCodes::COOKIE_NOT_FOUND);
        }

        return $cookie;
    }

    /**
     * Optimistic-locking pre-flight (closes 03/F9). The repository's
     * `WHERE version = ?` UPDATE is still the authoritative check, but
     * bailing here avoids parsing value objects + the uniqueness query
     * when we already lost the race.
     *
     * @throws DomainException When versions differ.
     */
    private function assertVersionMatches(Cookie $cookie, UpdateCookieCommand $command): void
    {
        if ($cookie->getVersion() === $command->expectedVersion) {
            return;
        }
        throw DomainException::concurrentModification(
            'Cookie',
            $command->id,
            $command->expectedVersion,
            $cookie->getVersion(),
            ErrorCodes::COOKIE_STATE_CONCURRENT_MODIFICATION
        );
    }

    /**
     * @throws DomainException When the new name (if changed) collides
     *                         with another row.
     */
    private function assertNameAvailable(Cookie $cookie, CookieName $name, int $id): void
    {
        if ($cookie->getName()->equals($name)) {
            return;
        }
        if (!$this->repository->existsByNameExcludingId($name->getValue(), $id)) {
            return;
        }
        throw DomainException::businessRuleViolation(
            'Cookie name must be unique',
            sprintf('A cookie with name "%s" already exists', $name->getValue()),
            ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE
        );
    }

    /**
     * Single drain site for the aggregate's event bag (closes 03/F1).
     */
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
        return UpdateCookieCommand::class;
    }

    protected function defaultErrorCode(): int
    {
        return ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED;
    }
}

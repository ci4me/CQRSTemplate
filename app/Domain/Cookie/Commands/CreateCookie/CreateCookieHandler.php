<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Bus\AbstractCommandHandler;
use App\Domain\Shared\Bus\ClockInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\AbstractDomainEvent;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use Psr\Log\LoggerInterface;

/**
 * Handler for {@see CreateCookieCommand}.
 *
 * Post-E08, the handler extends {@see AbstractCommandHandler}, which owns
 * the timing + logging + error-code-resolution boilerplate. The handler
 * body in {@see doHandle()} is the business logic ONLY — validate VOs,
 * uniqueness check, create entity, persist, dispatch event, return id.
 *
 * The create flow dispatches its `CookieCreatedEvent` envelope manually
 * rather than via the entity's event bag because the id is unknown at
 * `Cookie::create()` time and only emerges after `$repository->save()`.
 * Update/Delete/Restore can use the entity bag (the id is in scope by
 * then) and drain via {@see postCommit()}.
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 * @implements CommandHandlerInterface<CreateCookieCommand, int>
 */
final class CreateCookieHandler extends AbstractCommandHandler implements CommandHandlerInterface
{
    /**
     * @param CookieRepositoryInterface $repository      Persistence port.
     * @param EventDispatcherInterface  $eventDispatcher Dispatcher for the `CookieCreatedEvent`.
     * @param LoggerInterface           $logger          PSR-3 logger (channel: cookie.command.create).
     * @param ClockInterface            $clock           Monotonic time source for duration measurement.
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
     * @param CreateCookieCommand $command The create command DTO.
     * @return int Newly persisted cookie id.
     * @throws DomainException When the name collides with an existing row.
     */
    protected function doHandle(object $command): int
    {
        $name = CookieName::fromString($command->name);
        $price = CookiePrice::fromString($command->price);
        $this->assertNameAvailable($name);
        $cookie = Cookie::create(
            name: $name,
            description: $command->description,
            price: $price,
            stock: $command->stock,
            isActive: $command->isActive,
        );
        $cookieId = $this->repository->save($cookie, $command->createdBy);
        $this->eventDispatcher->dispatch(
            $this->buildCookieCreatedEvent($command, $cookieId, $name, $price)
        );

        return $cookieId;
    }

    /**
     * @throws DomainException When another row already owns this name.
     */
    private function assertNameAvailable(CookieName $name): void
    {
        if (!$this->repository->existsByName($name->getValue())) {
            return;
        }
        throw DomainException::businessRuleViolation(
            'Cookie name must be unique',
            sprintf('A cookie with name "%s" already exists', $name->getValue()),
            ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE
        );
    }

    /**
     * Build the `CookieCreatedEvent` envelope. Extracted from doHandle()
     * so the latter stays under the 20-line ceiling.
     */
    private function buildCookieCreatedEvent(
        CreateCookieCommand $command,
        int $cookieId,
        CookieName $name,
        CookiePrice $price
    ): CookieCreatedEvent {
        return new CookieCreatedEvent(
            eventId: AbstractDomainEvent::newId(),
            occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            actorId: $command->createdBy->isSystem() ? null : $command->createdBy->id,
            cookieId: $cookieId,
            cookieName: $name->getValue(),
            cookiePrice: $price->toDecimalString(),
            initialStock: $command->stock,
        );
    }

    protected function getDomain(): string
    {
        return 'Cookie';
    }

    protected function commandClass(): string
    {
        return CreateCookieCommand::class;
    }

    /**
     * The create handler's "save failed" / generic write-error code. Used
     * by the parent's failure logger when the exception carries no
     * domain-specific code of its own.
     */
    protected function defaultErrorCode(): int
    {
        return ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED;
    }
}

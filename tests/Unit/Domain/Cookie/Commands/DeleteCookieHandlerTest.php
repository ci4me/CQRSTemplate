<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieCommand;
use App\Domain\Cookie\Commands\DeleteCookie\DeleteCookieHandler;
use App\Domain\Cookie\Events\CookieDeleted\CookieDeletedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Actor;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class DeleteCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private EventDispatcherInterface $eventDispatcher;
    private DeleteCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = new Logger('test.cookie.commands', [new TestHandler()]);
        $this->handler = new DeleteCookieHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_deletes_cookie_successfully(): void
    {
        $command = new DeleteCookieCommand(id: 1, deletedBy: Actor::system('test'));
        $existing = CookieFactory::createPersistedCookie(['id' => 1]);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($existing);

        $this->repository->expects($this->once())
            ->method('delete')
            ->with(1)
            ->willReturn(true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieDeletedEvent::class));

        $this->handler->handle($command);
    }

    public function test_throws_exception_if_cookie_not_found(): void
    {
        $command = new DeleteCookieCommand(id: 999, deletedBy: Actor::system('test'));

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        $this->handler->handle($command);
    }
}

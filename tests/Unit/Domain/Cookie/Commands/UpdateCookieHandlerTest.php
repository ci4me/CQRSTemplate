<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class UpdateCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private EventDispatcherInterface $eventDispatcher;
    private UpdateCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = LoggerFactory::create('test.cookie.commands');
        $this->handler = new UpdateCookieHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_updates_cookie_successfully(): void
    {
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'Updated Cookie',
            description: 'New description',
            price: '3.99',
            stock: 150,
            isActive: true,
        updatedBy: Actor::system('test')
        );

        $existing = CookieFactory::createPersistedCookie(['id' => 1]);

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($existing);

        $this->repository->expects($this->once())
            ->method('existsByNameExcludingId')
            ->willReturn(false);

        $this->repository->expects($this->once())
            ->method('save');

        $this->eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieUpdatedEvent::class));

        $this->handler->handle($command);
    }

    public function test_throws_exception_if_cookie_not_found(): void
    {
        $command = new UpdateCookieCommand(
            id: 999,
            name: 'Test',
            description: null,
            price: '1.00',
            stock: 10,
            isActive: true,
        updatedBy: Actor::system('test')
        );

        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        $this->handler->handle($command);
    }

    public function test_throws_exception_if_name_already_taken(): void
    {
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'Taken Name',
            description: null,
            price: '2.99',
            stock: 100,
            isActive: true,
        updatedBy: Actor::system('test')
        );

        $existing = CookieFactory::createPersistedCookie(['id' => 1]);

        $this->repository->method('findById')->willReturn($existing);
        $this->repository->expects($this->once())
            ->method('existsByNameExcludingId')
            ->with('Taken Name', 1)
            ->willReturn(true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already exists');

        $this->handler->handle($command);
    }

    public function test_expected_version_mismatch_aborts_before_value_object_parse(): void
    {
        // Cookie loaded from the repo has version=1; the client thinks it's
        // still on version=0. The handler must abort BEFORE doing any
        // value-object parse / name-uniqueness query.
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'New Name',
            description: 'desc',
            price: '2.99',
            stock: 10,
            isActive: true,
            updatedBy: Actor::system('test'),
            expectedVersion: 0
        );

        $existing = CookieFactory::createPersistedCookie(['id' => 1, 'version' => 1]);
        $this->repository->method('findById')->willReturn($existing);
        $this->repository->expects($this->never())->method('existsByNameExcludingId');
        $this->repository->expects($this->never())->method('save');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modified by someone else');

        $this->handler->handle($command);
    }

    public function test_expected_version_matches_proceeds_normally(): void
    {
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'Same Name',
            description: 'desc',
            price: '2.99',
            stock: 10,
            isActive: true,
            updatedBy: Actor::system('test'),
            expectedVersion: 1
        );

        $existing = CookieFactory::createPersistedCookie([
            'id' => 1,
            'name' => 'Same Name',
            'version' => 1,
        ]);
        $this->repository->method('findById')->willReturn($existing);
        $this->repository->expects($this->once())->method('save');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $this->handler->handle($command);
    }
}

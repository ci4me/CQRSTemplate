<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class UpdateCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private EventDispatcher $eventDispatcher;
    private UpdateCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
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
            isActive: true
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
            isActive: true
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
            isActive: true
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
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieCommand;
use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieHandler;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use App\Domain\Cookie\Entities\Cookie;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class RestoreCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private EventDispatcher $eventDispatcher;
    private RestoreCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
        $logger = LoggerFactory::create('test.cookie.commands');
        $this->handler = new RestoreCookieHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_restores_a_soft_deleted_cookie(): void
    {
        $deletedCookie = $this->makeDeletedCookie(id: 7);
        $this->repository->method('findByIdWithTrashed')->with(7)->willReturn($deletedCookie);
        $this->repository->expects($this->once())->method('restore')->with(7)->willReturn(true);
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieRestoredEvent::class));

        $this->handler->handle(new RestoreCookieCommand(cookieId: 7, restoredBy: Actor::user(99)));
    }

    public function test_throws_when_cookie_does_not_exist(): void
    {
        $this->repository->method('findByIdWithTrashed')->willReturn(null);
        $this->repository->expects($this->never())->method('restore');

        $this->expectException(DomainException::class);

        $this->handler->handle(new RestoreCookieCommand(cookieId: 999, restoredBy: Actor::user(1)));
    }

    public function test_throws_when_cookie_is_not_actually_deleted(): void
    {
        $live = $this->makeLiveCookie(id: 7);
        $this->repository->method('findByIdWithTrashed')->willReturn($live);
        $this->repository->expects($this->never())->method('restore');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not deleted');

        $this->handler->handle(new RestoreCookieCommand(cookieId: 7, restoredBy: Actor::user(1)));
    }

    public function test_throws_when_repository_restore_fails(): void
    {
        $deleted = $this->makeDeletedCookie(id: 7);
        $this->repository->method('findByIdWithTrashed')->willReturn($deleted);
        $this->repository->method('restore')->willReturn(false);
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\RuntimeException::class);

        $this->handler->handle(new RestoreCookieCommand(cookieId: 7, restoredBy: Actor::user(1)));
    }

    private function makeDeletedCookie(int $id): Cookie
    {
        return Cookie::reconstitute(
            id: $id,
            name: CookieName::fromString('Old Cookie'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: false,
            createdAt: '2024-01-01 00:00:00',
            updatedAt: '2024-01-02 00:00:00',
            deletedAt: '2024-01-03 00:00:00'
        );
    }

    private function makeLiveCookie(int $id): Cookie
    {
        return Cookie::reconstitute(
            id: $id,
            name: CookieName::fromString('Live Cookie'),
            description: null,
            price: CookiePrice::fromString('1.00'),
            stock: 0,
            isActive: true,
            createdAt: '2024-01-01 00:00:00',
            updatedAt: null,
            deletedAt: null
        );
    }
}

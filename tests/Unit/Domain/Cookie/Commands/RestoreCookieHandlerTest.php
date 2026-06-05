<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieCommand;
use App\Domain\Cookie\Commands\RestoreCookie\RestoreCookieHandler;
use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieRestored\CookieRestoredEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class RestoreCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private RestoreCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.commands');
        $this->handler = new RestoreCookieHandler($this->repository, $logger);
    }

    public function test_restores_a_soft_deleted_cookie(): void
    {
        $deletedCookie = $this->makeDeletedCookie(id: 7);
        $this->repository->method('findByIdWithTrashed')->with(7)->willReturn($deletedCookie);

        // Round-4 R1 contract: the handler asks the AGGREGATE to restore
        // (clearing deletedAt + raising CookieRestoredEvent) and hands the
        // entity to the repository, which persists + drains in one
        // transaction. Handlers never dispatch.
        $this->repository->expects($this->once())
            ->method('restore')
            ->with($this->callback(static function (Cookie $cookie): bool {
                $events = $cookie->peekEvents();

                return !$cookie->isDeleted()
                    && count($events) === 1
                    && $events[0] instanceof CookieRestoredEvent
                    && $events[0]->cookieId === 7
                    && $events[0]->restoredBy === 99;
            }));

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

        try {
            $this->handler->handle(new RestoreCookieCommand(cookieId: 7, restoredBy: Actor::user(1)));
            $this->fail('Expected DomainException for not-deleted cookie');
        } catch (DomainException $e) {
            $this->assertStringContainsString('not deleted', $e->getMessage());
            // Round-4 R0: dedicated state code instead of mis-reusing COOKIE_NOT_FOUND.
            $this->assertSame(ErrorCodes::COOKIE_STATE_NOT_DELETED, $e->getErrorCode());
        }
    }

    public function test_propagates_concurrent_modification_from_repository(): void
    {
        $deleted = $this->makeDeletedCookie(id: 7);
        $this->repository->method('findByIdWithTrashed')->willReturn($deleted);
        $this->repository->method('restore')->willThrowException(
            DomainException::concurrentModification(
                'Cookie',
                '7',
                1,
                2,
                ErrorCodes::COOKIE_STATE_CONCURRENT_MODIFICATION
            )
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('modified by someone else');

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
            deletedAt: '2024-01-03 00:00:00',
            version: 1
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
            deletedAt: null,
            version: 1
        );
    }
}

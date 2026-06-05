<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\AdjustCookieStock\AdjustCookieStockCommand;
use App\Domain\Cookie\Commands\AdjustCookieStock\AdjustCookieStockHandler;
use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\Events\CookieStockChanged\CookieStockChangedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\ValueObjects\Actor;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\CookieFactory;
use Tests\Support\UnitTestCase;

#[AllowMockObjectsWithoutExpectations]
final class AdjustCookieStockHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private AdjustCookieStockHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $logger = LoggerFactory::create('test.cookie.commands');
        $this->handler = new AdjustCookieStockHandler($this->repository, $logger);
    }

    public function test_positive_adjustment_increases_stock_and_raises_event(): void
    {
        $existing = CookieFactory::createPersistedCookie(['id' => 3, 'stock' => 10]);
        $this->repository->method('findById')->with(3)->willReturn($existing);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (Cookie $cookie): bool {
                $events = $cookie->peekEvents();

                return $cookie->getStock() === 15
                    && count($events) === 1
                    && $events[0] instanceof CookieStockChangedEvent
                    && $events[0]->previousStock === 10
                    && $events[0]->newStock === 15
                    && $events[0]->reason === 'restock_received';
            }));

        $this->handler->handle(new AdjustCookieStockCommand(
            id: 3,
            adjustment: 5,
            reason: 'restock_received',
            adjustedBy: Actor::system('test')
        ));
    }

    public function test_negative_adjustment_decreases_stock(): void
    {
        $existing = CookieFactory::createPersistedCookie(['id' => 3, 'stock' => 10]);
        $this->repository->method('findById')->willReturn($existing);

        $this->repository->expects($this->once())
            ->method('save')
            ->with($this->callback(static function (Cookie $cookie): bool {
                $events = $cookie->peekEvents();

                return $cookie->getStock() === 4
                    && count($events) === 1
                    && $events[0] instanceof CookieStockChangedEvent
                    && $events[0]->reason === 'sale';
            }));

        $this->handler->handle(new AdjustCookieStockCommand(
            id: 3,
            adjustment: -6,
            reason: 'sale',
            adjustedBy: Actor::system('test')
        ));
    }

    public function test_zero_adjustment_is_rejected(): void
    {
        $this->repository->expects($this->never())->method('findById');

        $this->expectException(ValidationException::class);

        $this->handler->handle(new AdjustCookieStockCommand(
            id: 3,
            adjustment: 0,
            reason: 'noop',
            adjustedBy: Actor::system('test')
        ));
    }

    public function test_issuing_more_than_on_hand_is_business_rule_violation(): void
    {
        $existing = CookieFactory::createPersistedCookie(['id' => 3, 'stock' => 2]);
        $this->repository->method('findById')->willReturn($existing);
        $this->repository->expects($this->never())->method('save');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stock cannot be negative');

        $this->handler->handle(new AdjustCookieStockCommand(
            id: 3,
            adjustment: -5,
            reason: 'sale',
            adjustedBy: Actor::system('test')
        ));
    }

    public function test_unknown_cookie_is_not_found(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not found');

        $this->handler->handle(new AdjustCookieStockCommand(
            id: 999,
            adjustment: 5,
            reason: 'restock_received',
            adjustedBy: Actor::system('test')
        ));
    }
}

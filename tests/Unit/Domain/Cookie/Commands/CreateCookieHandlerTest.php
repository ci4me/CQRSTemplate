<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand;
use App\Domain\Cookie\Commands\CreateCookie\CreateCookieHandler;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for CreateCookieHandler.
 */
#[AllowMockObjectsWithoutExpectations]
final class CreateCookieHandlerTest extends UnitTestCase
{
    private CookieRepositoryInterface $repository;
    private EventDispatcher $eventDispatcher;
    private CreateCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
        $logger = LoggerFactory::create('test.cookie.commands');
        $this->handler = new CreateCookieHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_creates_cookie_successfully(): void
    {
        $command = new CreateCookieCommand(
            name: 'Chocolate Chip',
            description: 'Delicious',
            price: '2.99',
            stock: 100,
            isActive: true
        );

        $this->repository
            ->expects($this->once())
            ->method('existsByName')
            ->with('Chocolate Chip')
            ->willReturn(false);

        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturn(1);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieCreatedEvent::class));

        $result = $this->handler->handle($command);

        $this->assertEquals(1, $result);
    }

    public function test_throws_exception_if_name_already_exists(): void
    {
        $command = new CreateCookieCommand(
            name: 'Existing Cookie',
            description: null,
            price: '2.99',
            stock: 100,
            isActive: true
        );

        $this->repository
            ->expects($this->once())
            ->method('existsByName')
            ->with('Existing Cookie')
            ->willReturn(true);

        $this->repository
            ->expects($this->never())
            ->method('save');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already exists');

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_invalid_name(): void
    {
        $command = new CreateCookieCommand(
            name: 'AB',  // Too short
            description: null,
            price: '2.99',
            stock: 100,
            isActive: true
        );

        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_zero_price(): void
    {
        $command = new CreateCookieCommand(
            name: 'Test Cookie',
            description: null,
            price: '0.00',
            stock: 100,
            isActive: true
        );

        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_negative_stock(): void
    {
        $command = new CreateCookieCommand(
            name: 'Test Cookie',
            description: null,
            price: '2.99',
            stock: -10,
            isActive: true
        );

        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_dispatches_event_with_correct_data(): void
    {
        $command = new CreateCookieCommand(
            name: 'New Cookie',
            description: 'Fresh',
            price: '3.50',
            stock: 75,
            isActive: true
        );

        $this->repository
            ->method('existsByName')
            ->willReturn(false);

        $this->repository
            ->method('save')
            ->willReturn(42);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof CookieCreatedEvent &&
                       $event->cookieId === 42 &&
                       $event->cookieName === 'New Cookie' &&
                       $event->cookiePrice === '3.50' &&
                       $event->initialStock === 75;
            }));

        $this->handler->handle($command);
    }

    public function test_handles_null_description(): void
    {
        $command = new CreateCookieCommand(
            name: 'Simple Cookie',
            description: null,
            price: '1.99',
            stock: 50,
            isActive: true
        );

        $this->repository
            ->method('existsByName')
            ->willReturn(false);

        $this->repository
            ->method('save')
            ->willReturn(1);

        $result = $this->handler->handle($command);

        $this->assertEquals(1, $result);
    }

    public function test_handles_inactive_cookie(): void
    {
        $command = new CreateCookieCommand(
            name: 'Inactive Cookie',
            description: 'Not yet ready',
            price: '2.99',
            stock: 0,
            isActive: false
        );

        $this->repository
            ->method('existsByName')
            ->willReturn(false);

        $this->repository
            ->method('save')
            ->willReturn(1);

        $result = $this->handler->handle($command);

        $this->assertEquals(1, $result);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieCommand;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Cookie\Commands\UpdateCookie\UpdateCookieHandler;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\SystemClock;
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
        $this->handler = new UpdateCookieHandler(
            $this->repository,
            $this->eventDispatcher,
            $logger,
            new SystemClock()
        );
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
            updatedBy: Actor::system('test'),
            expectedVersion: 1
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
            updatedBy: Actor::system('test'),
            expectedVersion: 1
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
            updatedBy: Actor::system('test'),
            expectedVersion: 1
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

    public function test_rethrows_unknown_throwable_from_repository(): void
    {
        // Non-Validation, non-Domain exception => parent's determineErrorCode
        // resolves to defaultErrorCode() = COOKIE_REPOSITORY_SAVE_FAILED.
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
        ]);
        $this->repository->method('findById')->willReturn($existing);
        $this->repository->method('save')
            ->willThrowException(new \RuntimeException('database offline'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database offline');

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

    public function test_do_handle_is_under_the_twenty_line_ceiling(): void
    {
        $method = (new \ReflectionClass(UpdateCookieHandler::class))->getMethod('doHandle');
        $end = $method->getEndLine();
        $start = $method->getStartLine();
        $this->assertNotFalse($end);
        $this->assertNotFalse($start);
        $lines = ($end - $start) - 1;
        $this->assertLessThanOrEqual(
            20,
            $lines,
            sprintf('UpdateCookieHandler::doHandle() is %d lines; CLAUDE.md caps it at 20.', $lines)
        );
    }

    /**
     * The repository no longer drains pulled events (E07 stopped that);
     * the handler's parent template is the single dispatch site
     * (closes 03/F1). This test stubs a repository that explicitly
     * REFUSES to dispatch, then proves the handler's drain dispatches
     * exactly once per entity-raised event.
     */
    public function test_cookie_repository_does_not_dispatch_pulled_events(): void
    {
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'Renamed Cookie',
            description: 'still good',
            price: '3.50',
            stock: 25,
            isActive: true,
            updatedBy: Actor::system('test'),
            expectedVersion: 1
        );
        $existing = CookieFactory::createPersistedCookie([
            'id' => 1,
            'name' => 'Old Name',
            'version' => 1,
        ]);

        $this->repository->method('findById')->willReturn($existing);
        $this->repository->method('existsByNameExcludingId')->willReturn(false);
        // The mock repository implements `save()` with NO side-effect
        // dispatch on its own — proving any event delivered downstream
        // came from the handler's parent drain, not the repo.
        $this->repository->expects($this->once())->method('save');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(CookieUpdatedEvent::class));

        $this->handler->handle($command);
    }

    public function test_dispatch_count_equals_pull_events_count(): void
    {
        $command = new UpdateCookieCommand(
            id: 1,
            name: 'Brand New Name',
            description: null,
            price: '4.00',
            stock: 5,
            isActive: true,
            updatedBy: Actor::system('test'),
            expectedVersion: 1
        );
        $existing = CookieFactory::createPersistedCookie([
            'id' => 1,
            'name' => 'Old Name',
            'version' => 1,
        ]);

        $this->repository->method('findById')->willReturn($existing);
        $this->repository->method('existsByNameExcludingId')->willReturn(false);
        $this->repository->method('save');

        // Exactly one dispatch per entity-raised event — for a pure
        // update with no toggle, CookieUpdatedEvent is the only one.
        $dispatched = 0;
        $this->eventDispatcher->method('dispatch')->willReturnCallback(
            function () use (&$dispatched): void {
                $dispatched++;
            }
        );

        $this->handler->handle($command);

        $this->assertSame(1, $dispatched, 'Expected exactly one drained event dispatch.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Commands;

use App\Domain\User\Commands\DeleteUser\DeleteUserCommand;
use App\Domain\User\Commands\DeleteUser\DeleteUserHandler;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserDeleted\UserDeletedEvent;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for DeleteUserHandler.
 *
 * Tests cover:
 * - Successful soft delete
 * - User not found error
 * - Event dispatching with deleted user data
 * - Repository interaction verification
 */
#[AllowMockObjectsWithoutExpectations]
final class DeleteUserHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private EventDispatcher $eventDispatcher;
    private DeleteUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
        $logger = LoggerFactory::create('test.user.commands');
        $this->handler = new DeleteUserHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_deletes_user_successfully(): void
    {
        $command = new DeleteUserCommand(userId: 1);

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($existingUser);

        $this->repository
            ->expects($this->once())
            ->method('delete')
            ->with(1)
            ->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(UserDeletedEvent::class));

        $this->handler->handle($command);
    }

    public function test_throws_exception_when_user_not_found(): void
    {
        $command = new DeleteUserCommand(userId: 999);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->repository
            ->expects($this->never())
            ->method('delete');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->expectExceptionCode(ErrorCodes::USER_NOT_FOUND);

        $this->handler->handle($command);
    }

    public function test_dispatches_event_with_correct_data(): void
    {
        $command = new DeleteUserCommand(userId: 42);

        $existingUser = UserFactory::createPersistedUser(['id' => 42]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('delete')->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserDeletedEvent &&
                       $event->userId === 42 &&
                       is_int($event->deletedBy) &&
                       is_string($event->deletedAt);
            }));

        $this->handler->handle($command);
    }

    public function test_throws_exception_when_delete_fails(): void
    {
        $command = new DeleteUserCommand(userId: 1);

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository
            ->expects($this->once())
            ->method('delete')
            ->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to delete user');

        $this->handler->handle($command);
    }

    public function test_verifies_user_exists_before_attempting_delete(): void
    {
        $command = new DeleteUserCommand(userId: 1);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(null);

        // delete should never be called if user doesn't exist
        $this->repository
            ->expects($this->never())
            ->method('delete');

        // Event should never be dispatched if user doesn't exist
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        try {
            $this->handler->handle($command);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }
    }

    public function test_deletes_customer_user(): void
    {
        $command = new DeleteUserCommand(userId: 5);

        $customerUser = UserFactory::createPersistedUser([
            'id' => 5,
            'role' => 'customer'
        ]);

        $this->repository->method('findById')->willReturn($customerUser);
        $this->repository->method('delete')->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }

    public function test_deletes_admin_user(): void
    {
        $command = new DeleteUserCommand(userId: 10);

        $adminUser = UserFactory::createPersistedAdmin([
            'id' => 10
        ]);

        $this->repository->method('findById')->willReturn($adminUser);
        $this->repository->method('delete')->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }

    public function test_event_contains_valid_timestamp(): void
    {
        $command = new DeleteUserCommand(userId: 1);

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('delete')->willReturn(true);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                if (!$event instanceof UserDeletedEvent) {
                    return false;
                }

                // Verify timestamp is valid ISO 8601 format
                $timestamp = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $event->deletedAt);
                return $timestamp !== false;
            }));

        $this->handler->handle($command);
    }
}

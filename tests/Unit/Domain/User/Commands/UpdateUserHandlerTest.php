<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Commands;

use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\User\Commands\UpdateUser\UpdateUserCommand;
use App\Domain\User\Commands\UpdateUser\UpdateUserHandler;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\UserUpdated\UserUpdatedEvent;
use App\Domain\User\Ports\UserRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for UpdateUserHandler.
 *
 * Tests cover:
 * - Successful user update
 * - User not found error
 * - Email uniqueness validation (excluding current user)
 * - Changed field tracking (name, email, role, status)
 * - Event dispatching
 */
#[AllowMockObjectsWithoutExpectations]
final class UpdateUserHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private EventDispatcherInterface $eventDispatcher;
    private UpdateUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = new Logger('test.user.commands', [new TestHandler()]);
        $this->handler = new UpdateUserHandler($this->repository, $this->eventDispatcher, $logger);
    }

    public function test_updates_user_successfully(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'Jane Doe',
            email: 'jane.doe@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($existingUser);

        $this->repository
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn(null);

        $this->repository
            ->expects($this->once())
            ->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(UserUpdatedEvent::class));

        $this->handler->handle($command);
    }

    public function test_throws_exception_when_user_not_found(): void
    {
        $command = new UpdateUserCommand(
            userId: 999,
            name: 'Test User',
            email: 'test@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->repository
            ->expects($this->never())
            ->method('update');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->expectExceptionCode(ErrorCodes::USER_NOT_FOUND);

        $this->handler->handle($command);
    }

    public function test_validates_email_uniqueness_excluding_current_user(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'Jane Doe',
            email: 'existing@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'email' => 'old@example.com'
        ]);

        $conflictingUser = UserFactory::createPersistedUser([
            'id' => 2,
            'email' => 'existing@example.com'
        ]);

        $this->repository
            ->method('findById')
            ->willReturn($existingUser);

        $this->repository
            ->expects($this->once())
            ->method('findByEmail')
            ->willReturn($conflictingUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Email address is already in use');
        $this->expectExceptionCode(ErrorCodes::USER_VALIDATION_EMAIL);

        $this->handler->handle($command);
    }

    public function test_allows_same_email_for_same_user(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'Updated Name',
            email: 'john.doe@example.com', // Same email as existing
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'email' => 'john.doe@example.com'
        ]);

        $this->repository
            ->method('findById')
            ->willReturn($existingUser);

        // findByEmail should not be called when email hasn't changed
        $this->repository
            ->expects($this->never())
            ->method('findByEmail');

        $this->repository
            ->expects($this->once())
            ->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }

    public function test_tracks_name_change(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'Updated Name',
            email: 'john.doe@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       in_array('name', $event->updatedFields, true);
            }));

        $this->handler->handle($command);
    }

    public function test_tracks_email_change(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'new.email@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       in_array('email', $event->updatedFields, true);
            }));

        $this->handler->handle($command);
    }

    public function test_tracks_role_change(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'admin',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       in_array('role', $event->updatedFields, true);
            }));

        $this->handler->handle($command);
    }

    public function test_tracks_status_change(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'customer',
            status: 'inactive',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       in_array('status', $event->updatedFields, true);
            }));

        $this->handler->handle($command);
    }

    public function test_tracks_multiple_field_changes(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'Updated Name',
            email: 'updated@example.com',
            role: 'admin',
            status: 'inactive',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       count($event->updatedFields) === 4 &&
                       in_array('name', $event->updatedFields, true) &&
                       in_array('email', $event->updatedFields, true) &&
                       in_array('role', $event->updatedFields, true) &&
                       in_array('status', $event->updatedFields, true);
            }));

        $this->handler->handle($command);
    }

    public function test_dispatches_event_with_no_changes(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active'
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof UserUpdatedEvent &&
                       $event->userId === 1 &&
                       $event->updatedFields === [];
            }));

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_invalid_name(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'A', // Too short (min 2 chars)
            email: 'john.doe@example.com',
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_invalid_email(): void
    {
        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'invalid-email', // Invalid format
            role: 'customer',
            status: 'active',
            updatedBy: Actor::system('test')
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_non_admin_actor_cannot_change_role(): void
    {
        $permissions = $this->createMock(\App\Infrastructure\Auth\Services\PermissionService::class);
        $permissions->method('allows')->willReturn(false);

        $handler = new UpdateUserHandler(
            $this->repository,
            $this->eventDispatcher,
            new Logger('test.user.commands', [new TestHandler()]),
            $permissions
        );

        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'admin', // attempted privilege escalation
            status: 'active',
            updatedBy: Actor::user(42)
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->expects($this->never())->method('update');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\App\Domain\Shared\Exceptions\DomainException::class);

        $handler->handle($command);
    }

    public function test_non_admin_actor_cannot_change_status(): void
    {
        $permissions = $this->createMock(\App\Infrastructure\Auth\Services\PermissionService::class);
        $permissions->method('allows')->willReturn(false);

        $handler = new UpdateUserHandler(
            $this->repository,
            $this->eventDispatcher,
            new Logger('test.user.commands', [new TestHandler()]),
            $permissions
        );

        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'customer',
            status: 'inactive', // attempted lockout
            updatedBy: Actor::user(42)
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->expects($this->never())->method('update');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\App\Domain\Shared\Exceptions\DomainException::class);

        $handler->handle($command);
    }

    public function test_admin_actor_can_change_role(): void
    {
        $permissions = $this->createMock(\App\Infrastructure\Auth\Services\PermissionService::class);
        $permissions->method('allows')->willReturn(true);

        $handler = new UpdateUserHandler(
            $this->repository,
            $this->eventDispatcher,
            new Logger('test.user.commands', [new TestHandler()]),
            $permissions
        );

        $command = new UpdateUserCommand(
            userId: 1,
            name: 'John Doe',
            email: 'john.doe@example.com',
            role: 'admin',
            status: 'active',
            updatedBy: Actor::user(7)
        );

        $existingUser = UserFactory::createPersistedUser([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'role' => 'customer',
            'status' => 'active',
        ]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->expects($this->once())->method('update');
        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $handler->handle($command);
    }
}

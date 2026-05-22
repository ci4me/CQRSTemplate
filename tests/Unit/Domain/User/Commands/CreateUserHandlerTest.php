<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Commands;

use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\User\Commands\CreateUser\CreateUserCommand;
use App\Domain\User\Commands\CreateUser\CreateUserHandler;
use App\Domain\User\Events\UserRegistered\UserRegisteredEvent;
use App\Domain\User\Ports\UserRepositoryInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for CreateUserHandler (admin user creation).
 */
#[AllowMockObjectsWithoutExpectations]
final class CreateUserHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private EventDispatcherInterface $eventDispatcher;
    private CreateUserHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $logger = new Logger('test.user.commands', [new TestHandler()]);
        $this->handler = new CreateUserHandler(
            $this->repository,
            $this->eventDispatcher,
            $logger,
        );
    }

    public function test_creates_admin_user_with_full_flow(): void
    {
        $command = new CreateUserCommand(
            name: 'New Admin',
            email: 'admin@example.com',
            password: 'StrongP@ssw0rd!',
            role: 'admin',
        );

        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository
            ->expects($this->once())
            ->method('save')
            ->willReturn(42);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(UserRegisteredEvent::class));

        $result = $this->handler->handle($command);

        $this->assertSame(42, $result);
    }

    public function test_creates_customer_user_by_default(): void
    {
        $command = new CreateUserCommand(
            name: 'Customer User',
            email: 'customer@example.com',
            password: 'StrongP@ssw0rd!',
        );

        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository->method('save')->willReturn(7);

        $this->eventDispatcher->expects($this->once())->method('dispatch');

        $this->assertSame(7, $this->handler->handle($command));
    }

    public function test_throws_when_email_already_exists(): void
    {
        $command = new CreateUserCommand(
            name: 'Dup User',
            email: 'dup@example.com',
            password: 'StrongP@ssw0rd!',
        );

        $existing = UserFactory::createPersistedUser(['email' => 'dup@example.com']);
        $this->repository->method('findByEmail')->willReturn($existing);

        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('email already exists');

        $this->handler->handle($command);
    }

    public function test_propagates_invalid_email_validation(): void
    {
        $command = new CreateUserCommand(
            name: 'Bad Email',
            email: 'not-an-email',
            password: 'StrongP@ssw0rd!',
        );

        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\Throwable::class);

        $this->handler->handle($command);
    }

    public function test_propagates_invalid_role_from_enum(): void
    {
        $command = new CreateUserCommand(
            name: 'Bad Role',
            email: 'role@example.com',
            password: 'StrongP@ssw0rd!',
            role: 'superuser',
        );

        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\Throwable::class);

        $this->handler->handle($command);
    }

    public function test_propagates_repository_save_exception(): void
    {
        $command = new CreateUserCommand(
            name: 'DB Error',
            email: 'dberr@example.com',
            password: 'StrongP@ssw0rd!',
        );

        $this->repository->method('findByEmail')->willReturn(null);
        $this->repository
            ->method('save')
            ->willThrowException(new \RuntimeException('database unavailable'));

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->handler->handle($command);
    }
}

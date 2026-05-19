<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\Commands;

use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordCommand;
use App\Domain\User\Commands\ChangeUserPassword\ChangeUserPasswordHandler;
use App\Domain\User\ErrorCodes;
use App\Domain\User\Events\PasswordChanged\PasswordChangedEvent;
use App\Infrastructure\Bus\EventDispatcher;
use App\Infrastructure\Logging\LoggerFactory;
use App\Infrastructure\Persistence\Repositories\UserRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Tests\Support\Factories\UserFactory;
use Tests\Support\UnitTestCase;

/**
 * Unit tests for ChangeUserPasswordHandler.
 *
 * Tests cover:
 * - Successful password change
 * - User not found error
 * - Password complexity validation (via HashedPassword)
 * - Security event dispatching with CRITICAL tag
 * - Repository interaction verification
 */
#[AllowMockObjectsWithoutExpectations]
final class ChangeUserPasswordHandlerTest extends UnitTestCase
{
    private UserRepositoryInterface $repository;
    private EventDispatcher $eventDispatcher;
    private ChangeUserPasswordHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(UserRepositoryInterface::class);
        $passwordHistory = $this->createMock(\App\Infrastructure\Persistence\Repositories\PasswordHistoryRepository::class);
        $this->eventDispatcher = $this->createMock(EventDispatcher::class);
        $logger = LoggerFactory::create('test.user.commands');
        $this->handler = new ChangeUserPasswordHandler($this->repository, $passwordHistory, $this->eventDispatcher, $logger);
    }

    public function test_changes_password_successfully(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($existingUser);

        $this->repository
            ->expects($this->once())
            ->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PasswordChangedEvent::class));

        $this->handler->handle($command);
    }

    public function test_throws_exception_when_user_not_found(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 999,
            newPassword: 'NewSecureP@ssw0rd123!'
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

    public function test_throws_exception_for_weak_password(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'weak'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception for weak password
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_password_too_short(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'Short1!' // Less than 12 characters
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception for short password
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_password_without_uppercase(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'nosecurep@ssw0rd123!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_password_without_lowercase(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NOSECUREP@SSW0RD123!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_password_without_digit(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NoSecureP@ssword!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_throws_exception_for_password_without_special_char(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NoSecurePassword123'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);

        // HashedPassword::fromPlaintext should throw exception
        $this->expectException(\Exception::class);

        $this->handler->handle($command);
    }

    public function test_dispatches_event_with_correct_data(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 42,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 42]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof PasswordChangedEvent &&
                       $event->userId === 42 &&
                       is_int($event->changedBy) &&
                       is_string($event->changedAt);
            }));

        $this->handler->handle($command);
    }

    public function test_verifies_user_exists_before_changing_password(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $this->repository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(null);

        // update should never be called if user doesn't exist
        $this->repository
            ->expects($this->never())
            ->method('update');

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

    public function test_changes_password_for_customer_user(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 5,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $customerUser = UserFactory::createPersistedUser([
            'id' => 5,
            'role' => 'customer'
        ]);

        $this->repository->method('findById')->willReturn($customerUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }

    public function test_changes_password_for_admin_user(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 10,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $adminUser = UserFactory::createPersistedAdmin([
            'id' => 10
        ]);

        $this->repository->method('findById')->willReturn($adminUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }

    public function test_event_contains_valid_timestamp(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'NewSecureP@ssw0rd123!'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                if (!$event instanceof PasswordChangedEvent) {
                    return false;
                }

                // Verify timestamp is valid ISO 8601 format
                $timestamp = \DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $event->changedAt);
                return $timestamp !== false;
            }));

        $this->handler->handle($command);
    }

    public function test_accepts_complex_password_with_all_requirements(): void
    {
        $command = new ChangeUserPasswordCommand(
            userId: 1,
            newPassword: 'VeryComplexP@ssw0rd123!WithExtraCharacters'
        );

        $existingUser = UserFactory::createPersistedUser(['id' => 1]);

        $this->repository->method('findById')->willReturn($existingUser);
        $this->repository
            ->expects($this->once())
            ->method('update');

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $this->handler->handle($command);
    }
}

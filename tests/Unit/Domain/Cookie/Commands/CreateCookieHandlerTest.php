<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Cookie\Commands;

use App\Domain\Cookie\Commands\CreateCookie\CreateCookieCommand;
use App\Domain\Shared\ValueObjects\Actor;
use App\Domain\Cookie\Commands\CreateCookie\CreateCookieHandler;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Domain\Shared\Events\EventDispatcherInterface;
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
    private EventDispatcherInterface $eventDispatcher;
    private CreateCookieHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(CookieRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
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
            createdBy: Actor::system('test'),
        isActive: true
        );

        $this->repository
            ->expects($this->once())
            ->method('existsByName')
            ->with($this->callback(static fn($name): bool =>
                $name instanceof \App\Domain\Cookie\ValueObjects\CookieName
                && $name->getValue() === 'Chocolate Chip'))
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
            createdBy: Actor::system('test'),
        isActive: true
        );

        $this->repository
            ->expects($this->once())
            ->method('existsByName')
            ->with($this->callback(static fn($name): bool =>
                $name instanceof \App\Domain\Cookie\ValueObjects\CookieName
                && $name->getValue() === 'Existing Cookie'))
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
            createdBy: Actor::system('test'),
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
            createdBy: Actor::system('test'),
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
            createdBy: Actor::system('test'),
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
            createdBy: Actor::system('test'),
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
            createdBy: Actor::system('test'),
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
            createdBy: Actor::system('test'),
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

    public function test_rethrows_validation_exception_from_value_object(): void
    {
        // Empty name => ValidationException with COOKIE_VALIDATION_NAME code.
        // Exercises the ValidationException branch of determineErrorCode.
        $command = new CreateCookieCommand(
            name: '',
            description: null,
            price: '2.99',
            stock: 10,
            createdBy: Actor::system('test'),
            isActive: true,
        );

        $this->expectException(ValidationException::class);
        $this->handler->handle($command);
    }

    public function test_rethrows_unknown_throwable_from_repository(): void
    {
        // Non-Validation, non-Domain exception => falls through to
        // COOKIE_REPOSITORY_SAVE_FAILED (final return on line 164).
        $command = new CreateCookieCommand(
            name: 'Test Cookie',
            description: null,
            price: '2.99',
            stock: 10,
            createdBy: Actor::system('test'),
            isActive: true,
        );

        $this->repository->method('existsByName')->willReturn(false);
        $this->repository->method('save')
            ->willThrowException(new \RuntimeException('database is down'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database is down');
        $this->handler->handle($command);
    }

    /**
     * Exercises the str_contains() match arms in determineErrorCode by
     * having the repository raise a generic DomainException (errorCode=0)
     * whose message contains the discriminator keyword.
     *
     * @param string $message Message containing the discriminator keyword
     * @param int    $unused  Unused — present so PHPUnit's data provider is
     *                        explicit about which arm is being targeted
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('domainExceptionMessageProvider')]
    public function test_determine_error_code_match_arms_for_zero_coded_domain_exceptions(
        string $message,
        int $unused
    ): void {
        $command = new CreateCookieCommand(
            name: 'Test Cookie',
            description: null,
            price: '2.99',
            stock: 10,
            createdBy: Actor::system('test'),
            isActive: true,
        );

        $this->repository->method('existsByName')->willReturn(false);
        $this->repository->method('save')
            ->willThrowException(new DomainException($message, 0));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);
        $this->handler->handle($command);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function domainExceptionMessageProvider(): array
    {
        // Each message hits a different match-arm in determineErrorCode.
        return [
            'name must be unique arm' => ['Cookie name must be unique here', 0],
            'stock arm' => ['stock fell below zero', 0],
            'name arm' => ['the name is suspicious', 0],
            'price arm' => ['price could not be persisted', 0],
            'default arm' => ['repository connection lost', 0],
        ];
    }
}

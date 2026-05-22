<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\CreateCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieCreated\CookieCreatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Handler for CreateCookieCommand.
 *
 * Responsibilities:
 * 1. Validate command data using domain rules
 * 2. Check business rules (e.g., name uniqueness)
 * 3. Create Cookie domain entity
 * 4. Persist via repository
 * 5. Dispatch domain event
 *
 * Business Rules Enforced:
 * - Cookie name must be unique (case-insensitive)
 * - Cookie name must be 3-100 characters
 * - Price must be greater than zero
 * - Stock cannot be negative
 *
 * Why Handler Pattern:
 * - Separates request (Command) from execution (Handler)
 * - Single responsibility (only handles cookie creation)
 * - Easy to test in isolation
 * - Can be decorated with cross-cutting concerns (logging, transactions)
 *
 * @package App\Domain\Cookie\Commands\CreateCookie
 * @implements CommandHandlerInterface<CreateCookieCommand, int>
 */
final readonly class CreateCookieHandler implements CommandHandlerInterface
{
    /**
     * Create a new CreateCookieHandler.
     *
     * @param CookieRepositoryInterface $repository      For persistence operations
     * @param EventDispatcherInterface  $eventDispatcher For dispatching domain events
     * @param LoggerInterface           $logger          For logging command execution (channel: cookie.command.create)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the CreateCookieCommand.
     *
     * @param CreateCookieCommand $command The creation command
     * @return int The ID of the newly created cookie
     * @throws DomainException If business rules are violated
     */
    public function handle(object $command): int
    {
        $startTime = microtime(true);

        $this->logger->info('Creating cookie', [
            'domain' => 'Cookie',
            'command' => 'CreateCookieCommand',
            'name' => $command->name,
            'price' => $command->price,
            'stock' => $command->stock,
            'isActive' => $command->isActive,
        ]);

        try {
            // Create Value Objects (this validates format/constraints)
            $name = CookieName::fromString($command->name);
            $price = CookiePrice::fromString($command->price);

            // Check business rule: name must be unique
            if ($this->repository->existsByName($name->getValue())) {
                throw DomainException::businessRuleViolation(
                    'Cookie name must be unique',
                    sprintf('A cookie with name "%s" already exists', $name->getValue()),
                    ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE
                );
            }

            // Create the domain entity
            $cookie = Cookie::create(
                name: $name,
                description: $command->description,
                price: $price,
                stock: $command->stock,
                isActive: $command->isActive
            );

            // Persist to database; stamp created_by/updated_by audit columns.
            $cookieId = $this->repository->save($cookie, $command->createdBy);

            // Dispatch domain event
            $this->eventDispatcher->dispatch(new CookieCreatedEvent(
                cookieId: $cookieId,
                cookieName: $name->getValue(),
                cookiePrice: $price->toDecimalString(),
                initialStock: $command->stock
            ));

            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logger->info('Cookie created successfully', [
                'domain' => 'Cookie',
                'command' => 'CreateCookieCommand',
                'cookieId' => $cookieId,
                'duration_ms' => round($durationMs, 2),
            ]);

            return $cookieId;
        } catch (\Throwable $e) {
            $durationMs = (microtime(true) - $startTime) * 1000;

            $errorCode = $this->determineErrorCode($e);

            $this->logger->error('Failed to create cookie', [
                'domain' => 'Cookie',
                'command' => 'CreateCookieCommand',
                'exception' => $e->getMessage(),
                'exceptionClass' => $e::class,
                'name' => $command->name,
                'error_code' => $errorCode,
                'duration_ms' => round($durationMs, 2),
            ]);

            throw $e;
        }
    }

    /**
     * Determine appropriate error code based on exception type and context.
     */
    private function determineErrorCode(\Throwable $e): int
    {
        if ($e instanceof ValidationException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        if ($e instanceof DomainException) {
            if ($e->getErrorCode() !== 0) {
                return $e->getErrorCode();
            }

            return match (true) {
                str_contains($e->getMessage(), 'name must be unique') => ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE,
                str_contains($e->getMessage(), 'stock') => ErrorCodes::COOKIE_BUSINESS_RULE_STOCK_NEGATIVE,
                str_contains($e->getMessage(), 'name') => ErrorCodes::COOKIE_VALIDATION_NAME,
                str_contains($e->getMessage(), 'price') => ErrorCodes::COOKIE_VALIDATION_PRICE,
                default => ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED,
            };
        }

        return ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED;
    }
}

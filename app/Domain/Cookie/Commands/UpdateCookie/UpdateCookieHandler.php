<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Cookie\Entities\Cookie;
use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Events\CookieUpdated\CookieUpdatedEvent;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use App\Infrastructure\Bus\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Handler for UpdateCookieCommand.
 *
 * Responsibilities:
 * 1. Load existing cookie from repository
 * 2. Validate new data using domain rules
 * 3. Check business rules (e.g., name uniqueness if changed)
 * 4. Update cookie entity
 * 5. Persist changes
 * 6. Dispatch domain event
 *
 * Business Rules Enforced:
 * - Cookie must exist
 * - If name changed, new name must be unique
 * - Same validation rules as create
 *
 * @package App\Domain\Cookie\Commands\UpdateCookie
 */
final readonly class UpdateCookieHandler
{
    /**
     * Create a new UpdateCookieHandler.
     *
     * @param CookieRepositoryInterface $repository For persistence operations
     * @param EventDispatcher $eventDispatcher For dispatching domain events
     * @param LoggerInterface $logger For logging command execution (channel: cookie.command.update)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the UpdateCookieCommand.
     *
     * @param UpdateCookieCommand $command The update command
     * @throws DomainException If cookie not found or business rules violated
     */
    public function handle(UpdateCookieCommand $command): void
    {
        $startTime = microtime(true);

        $this->logger->info('Updating cookie', [
            'domain' => 'Cookie',
            'command' => 'UpdateCookieCommand',
            'cookieId' => $command->id,
            'name' => $command->name,
            'price' => $command->price,
            'stock' => $command->stock,
            'isActive' => $command->isActive,
        ]);

        try {
            // Load existing cookie
            $cookie = $this->repository->findById($command->id);

            if ($cookie === null) {
                throw DomainException::notFound('Cookie', $command->id, ErrorCodes::COOKIE_NOT_FOUND);
            }

            // B13: snapshot previous state BEFORE mutation so the event can
            // carry a structured diff for the audit log.
            $previousState = $this->snapshot($cookie);

            // Create Value Objects (validates format/constraints)
            $name = CookieName::fromString($command->name);
            $price = CookiePrice::fromString($command->price);

            // Check business rule: if name changed, new name must be unique
            if (!$cookie->getName()->equals($name)) {
                if ($this->repository->existsByNameExcludingId($name->getValue(), $command->id)) {
                    throw DomainException::businessRuleViolation(
                        'Cookie name must be unique',
                        sprintf('A cookie with name "%s" already exists', $name->getValue()),
                        ErrorCodes::COOKIE_BUSINESS_RULE_NAME_DUPLICATE
                    );
                }
            }

            // Update the domain entity
            $cookie->update(
                name: $name,
                description: $command->description,
                price: $price,
                stock: $command->stock,
                isActive: $command->isActive
            );

            $newState = $this->snapshot($cookie);

            // Persist changes; stamps updated_by on the row.
            $this->repository->save($cookie, $command->updatedBy);

            // Dispatch domain event with structured before/after diff
            $this->eventDispatcher->dispatch(new CookieUpdatedEvent(
                cookieId: $command->id,
                cookieName: $name->getValue(),
                cookiePrice: $price->toDecimalString(),
                previousState: $previousState,
                newState: $newState
            ));

            $durationMs = (microtime(true) - $startTime) * 1000;

            $this->logger->info('Cookie updated successfully', [
                'domain' => 'Cookie',
                'command' => 'UpdateCookieCommand',
                'cookieId' => $command->id,
                'duration_ms' => round($durationMs, 2),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update cookie', [
                'domain' => 'Cookie',
                'command' => 'UpdateCookieCommand',
                'error_code' => $this->determineErrorCode($e),
                'exception' => $e->getMessage(),
                'cookieId' => $command->id,
            ]);

            throw $e;
        }
    }

    private function determineErrorCode(\Throwable $e): int
    {
        if ($e instanceof ValidationException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        if ($e instanceof DomainException && $e->getErrorCode() !== 0) {
            return $e->getErrorCode();
        }

        return ErrorCodes::COOKIE_REPOSITORY_SAVE_FAILED;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function snapshot(Cookie $cookie): array
    {
        return [
            'id' => $cookie->getId(),
            'name' => $cookie->getName()->getValue(),
            'description' => $cookie->getDescription(),
            'price' => $cookie->getPrice()->toDecimalString(),
            'stock' => $cookie->getStock(),
            'is_active' => $cookie->getIsActive(),
        ];
    }
}

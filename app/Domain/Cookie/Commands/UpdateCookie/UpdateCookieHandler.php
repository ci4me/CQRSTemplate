<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\UpdateCookie;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Cookie\ValueObjects\CookieName;
use App\Domain\Cookie\ValueObjects\CookiePrice;
use App\Domain\Shared\Events\EventDispatcherInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
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
     * @param CookieRepositoryInterface $repository      For persistence operations
     * @param EventDispatcherInterface  $eventDispatcher For dispatching domain events
     * @param LoggerInterface           $logger          For logging command execution (channel: cookie.command.update)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
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

            // CONCURRENCY: pre-flight optimistic-locking check. The repository
            // also gates the UPDATE on `WHERE version = ?`, but pre-flighting
            // here avoids the value-object parse + name-uniqueness query for
            // a request that already lost the race. Null expectedVersion is
            // backwards-compatible — legacy callers skip the check and lean
            // on the repository's row-level guard.
            if ($command->expectedVersion !== null && $cookie->getVersion() !== $command->expectedVersion) {
                throw DomainException::concurrentModification(
                    'Cookie',
                    $command->id,
                    $command->expectedVersion,
                    $cookie->getVersion(),
                    ErrorCodes::COOKIE_STATE_CONCURRENT_MODIFICATION
                );
            }

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

            // Update the domain entity (also raises CookieUpdatedEvent with
            // before/after diff via AggregateRoot).
            $cookie->update(
                name: $name,
                description: $command->description,
                price: $price,
                stock: $command->stock,
                isActive: $command->isActive
            );

            // Persist changes; stamps updated_by on the row.
            $this->repository->save($cookie, $command->updatedBy);

            // Drain entity-raised events explicitly so dispatch is
            // deterministic regardless of repository implementation
            // (the repository ALSO drains, but a mock repo in tests
            // won't — the drain here is the contract the test asserts).
            foreach ($cookie->pullEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }

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

    /**
     * Pick the most specific ErrorCodes constant for a failed update.
     *
     * Prefers the exception's own getErrorCode() when present (validation
     * + domain exceptions carry it); falls back to COOKIE_REPOSITORY_SAVE_FAILED
     * for raw infrastructure errors so the structured log line always carries
     * a numeric error_code field.
     */
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
}

<?php

declare(strict_types=1);

namespace App\Domain\Cookie\Commands\AdjustCookieStock;

use App\Domain\Cookie\ErrorCodes;
use App\Domain\Cookie\Ports\CookieRepositoryInterface;
use App\Domain\Shared\Bus\CommandHandlerInterface;
use App\Domain\Shared\Exceptions\DomainException;
use App\Domain\Shared\Exceptions\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Handler for AdjustCookieStockCommand (round-4 R3).
 *
 * Responsibilities:
 * 1. Verify the cookie exists
 * 2. Apply the signed stock delta on the AGGREGATE (which enforces the
 *    never-negative and MAX_STOCK invariants and raises
 *    CookieStockChangedEvent with the business reason)
 * 3. Persist via repository (drains the event outbox-first, same transaction)
 *
 * Business Rules Enforced:
 * - Adjustment must be non-zero
 * - Stock can never go negative (CookieStock invariant)
 * - Stock can never exceed CookieStock::MAX_STOCK (overflow guard)
 *
 * @package App\Domain\Cookie\Commands\AdjustCookieStock
 */
final readonly class AdjustCookieStockHandler implements CommandHandlerInterface
{
    /**
     * Create a new AdjustCookieStockHandler.
     *
     * @param CookieRepositoryInterface $repository For persistence operations
     * @param LoggerInterface           $logger     For logging command execution (channel: cookie.command.adjust-stock)
     */
    public function __construct(
        private CookieRepositoryInterface $repository,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Handle the AdjustCookieStockCommand.
     *
     * @param AdjustCookieStockCommand $command The stock-adjustment command
     * @throws ValidationException When the adjustment is zero or out of range
     * @throws DomainException When the cookie is missing or stock would go negative
     */
    public function handle(AdjustCookieStockCommand $command): void
    {
        $startTime = hrtime(true);

        $this->logger->info('Adjusting cookie stock', [
            'domain' => 'Cookie',
            'command' => 'AdjustCookieStockCommand',
            'cookieId' => $command->id,
            'adjustment' => $command->adjustment,
            'reason' => $command->reason,
        ]);

        try {
            $this->applyAdjustment($command);
            $this->logSuccess($command, $startTime);
        } catch (\Throwable $e) {
            $this->logFailure($command, $e, $startTime);

            throw $e;
        }
    }

    /**
     * Load the aggregate, apply the signed delta, persist.
     *
     * @throws ValidationException When the adjustment is zero
     * @throws DomainException When the cookie is missing or an invariant fails
     */
    private function applyAdjustment(AdjustCookieStockCommand $command): void
    {
        if ($command->adjustment === 0) {
            throw ValidationException::invalidFormat(
                'adjustment',
                'a non-zero signed quantity',
                ErrorCodes::COOKIE_VALIDATION_STOCK
            );
        }

        $cookie = $this->repository->findById($command->id);
        if ($cookie === null) {
            throw DomainException::notFound('Cookie', $command->id, ErrorCodes::COOKIE_NOT_FOUND);
        }

        // The aggregate enforces never-negative / MAX_STOCK and raises
        // CookieStockChangedEvent carrying the business reason.
        if ($command->adjustment > 0) {
            $cookie->increaseStock($command->adjustment, $command->reason);
        } else {
            $cookie->decreaseStock(-$command->adjustment, $command->reason);
        }

        $this->repository->save($cookie, $command->adjustedBy);
    }

    /**
     * Structured success log with duration.
     */
    private function logSuccess(AdjustCookieStockCommand $command, int|float $startTime): void
    {
        $durationMs = (hrtime(true) - $startTime) / 1_000_000;
        $this->logger->info('Cookie stock adjusted successfully', [
            'domain' => 'Cookie',
            'command' => 'AdjustCookieStockCommand',
            'cookieId' => $command->id,
            'adjustment' => $command->adjustment,
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    /**
     * Structured failure log with error code and duration.
     */
    private function logFailure(AdjustCookieStockCommand $command, \Throwable $e, int|float $startTime): void
    {
        $durationMs = (hrtime(true) - $startTime) / 1_000_000;
        $this->logger->error('Failed to adjust cookie stock', [
            'domain' => 'Cookie',
            'command' => 'AdjustCookieStockCommand',
            'error_code' => $this->determineErrorCode($e),
            'exception' => $e->getMessage(),
            'exceptionClass' => $e::class,
            'cookieId' => $command->id,
            'duration_ms' => round($durationMs, 2),
        ]);
    }

    /**
     * Pick the most specific ErrorCodes constant for a failed adjustment.
     *
     * Prefers the exception's own getErrorCode() when present; falls back
     * to COOKIE_REPOSITORY_SAVE_FAILED for raw infrastructure errors.
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

<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadGenericMismatch;

use App\Domain\Shared\Bus\CommandHandlerInterface;

/**
 * Fixture: handler whose @implements generic points at a class that
 * doesn't exist next to it in the slice directory.
 *
 * Rule 3 (HandleParamTypeMatchesCommandRule) MUST fire on this file.
 *
 * @implements CommandHandlerInterface<NonExistentCommand, void>
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadGenericMismatch
 */
final readonly class CreateBadGenericMismatchHandler implements CommandHandlerInterface
{
    /**
     * @param object $command Command DTO.
     */
    public function handle(object $command): void
    {
    }
}

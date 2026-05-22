<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateGood;

use App\Domain\Shared\Bus\CommandHandlerInterface;

/**
 * Fixture: well-formed Command handler.
 *
 * Implements CommandHandlerInterface AND declares the matching generic.
 * All three rules must STAY SILENT on this file.
 *
 * @implements CommandHandlerInterface<CreateGoodCommand, void>
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateGood
 */
final readonly class CreateGoodHandler implements CommandHandlerInterface
{
    /**
     * @param CreateGoodCommand $command The good command.
     */
    public function handle(object $command): void
    {
    }
}

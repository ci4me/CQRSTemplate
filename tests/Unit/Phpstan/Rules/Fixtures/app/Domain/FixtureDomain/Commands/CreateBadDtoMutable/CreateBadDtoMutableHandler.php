<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadDtoMutable;

use App\Domain\Shared\Bus\CommandHandlerInterface;

/**
 * Fixture: handler matching the BadDtoMutable Command so Rule 1 stays silent.
 *
 * @implements CommandHandlerInterface<CreateBadDtoMutableCommand, void>
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadDtoMutable
 */
final readonly class CreateBadDtoMutableHandler implements CommandHandlerInterface
{
    /**
     * @param CreateBadDtoMutableCommand $command Command DTO.
     */
    public function handle(object $command): void
    {
    }
}

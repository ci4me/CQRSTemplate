<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadDtoMutable;

/**
 * Fixture: Command DTO that is NOT declared `final readonly`.
 *
 * Rule 2 (CommandQueryDtoIsReadonlyRule) MUST fire on this file.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadDtoMutable
 */
final class CreateBadDtoMutableCommand
{
    /**
     * Construct.
     */
    public function __construct(public string $name)
    {
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadGenericMismatch;

/**
 * Fixture: Command DTO sitting next to a handler whose @implements
 * generic points at a DIFFERENT class name.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadGenericMismatch
 */
final readonly class CreateBadGenericMismatchCommand
{
    /**
     * Construct.
     */
    public function __construct(public string $name)
    {
    }
}

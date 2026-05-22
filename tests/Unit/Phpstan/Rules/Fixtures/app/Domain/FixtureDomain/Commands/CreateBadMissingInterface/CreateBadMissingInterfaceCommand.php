<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadMissingInterface;

/**
 * Fixture: well-formed Command DTO (used to keep Rule 2 silent here).
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadMissingInterface
 */
final readonly class CreateBadMissingInterfaceCommand
{
    /**
     * Construct.
     */
    public function __construct(public string $name)
    {
    }
}

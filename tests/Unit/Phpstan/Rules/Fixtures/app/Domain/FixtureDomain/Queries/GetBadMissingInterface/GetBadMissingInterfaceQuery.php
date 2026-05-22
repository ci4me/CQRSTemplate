<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetBadMissingInterface;

/**
 * Fixture: well-formed Query DTO so Rule 2 stays silent on this slice.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetBadMissingInterface
 */
final readonly class GetBadMissingInterfaceQuery
{
    /**
     * Construct.
     */
    public function __construct(public int $id)
    {
    }
}

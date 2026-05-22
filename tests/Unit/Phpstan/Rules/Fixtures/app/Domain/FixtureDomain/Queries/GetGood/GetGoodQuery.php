<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetGood;

/**
 * Fixture: well-formed Query DTO.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetGood
 */
final readonly class GetGoodQuery
{
    /**
     * Construct.
     */
    public function __construct(public int $id)
    {
    }
}

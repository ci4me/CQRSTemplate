<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateGood;

/**
 * Fixture: well-formed Command DTO.
 *
 * Used by CommandQueryDtoIsReadonlyRuleTest's PASSING case — `final readonly`
 * keeps the bus seam immutable. Lives under /app/Domain/.../Commands/.../ so
 * the path-matching predicate in every rule fires.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateGood
 */
final readonly class CreateGoodCommand
{
    /**
     * Construct.
     */
    public function __construct(public string $name)
    {
    }
}

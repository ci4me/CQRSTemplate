<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetGood;

use App\Domain\Shared\Bus\QueryHandlerInterface;

/**
 * Fixture: well-formed Query handler.
 *
 * @implements QueryHandlerInterface<GetGoodQuery, ?string>
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetGood
 */
final readonly class GetGoodHandler implements QueryHandlerInterface
{
    /**
     * @param GetGoodQuery $query Query DTO.
     */
    public function handle(object $query): ?string
    {
        return null;
    }
}

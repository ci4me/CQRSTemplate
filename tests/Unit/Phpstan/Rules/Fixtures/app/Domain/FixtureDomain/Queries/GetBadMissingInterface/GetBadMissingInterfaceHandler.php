<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetBadMissingInterface;

/**
 * Fixture: Query handler that FORGOT to implement QueryHandlerInterface.
 *
 * Rule 1 (HandlerImplementsInterfaceRule) MUST fire on this file in the
 * Queries branch.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetBadMissingInterface
 */
final readonly class GetBadMissingInterfaceHandler
{
    /**
     * Bogus handle method.
     */
    public function handle(object $query): ?string
    {
        return null;
    }
}

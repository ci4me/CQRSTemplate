<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadMissingInterface;

/**
 * Fixture: handler that FORGOT to implement CommandHandlerInterface.
 *
 * Rule 1 (HandlerImplementsInterfaceRule) MUST fire on this file.
 *
 * @package Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadMissingInterface
 */
final readonly class CreateBadMissingInterfaceHandler
{
    /**
     * Bogus handle method.
     */
    public function handle(object $command): void
    {
    }
}

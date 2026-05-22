<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\HandlerImplementsInterfaceRule;

/**
 * E05.5 — exercises HandlerImplementsInterfaceRule against two
 * file-system fixtures: one well-formed handler that implements the
 * CQRS interface (rule MUST stay silent) and one that forgot to add
 * the `implements` clause (rule MUST fire with the documented
 * `cqrs.handlerMissingInterface` identifier).
 *
 * Fixtures live under tests/Unit/Phpstan/Rules/Fixtures/app/Domain/...
 * because the rule's scope predicate matches on the `/app/Domain/...`
 * substring in the file path. They are excluded from the main phpstan
 * pass via phpstan.neon `excludePaths`.
 *
 * @extends RuleTestCase<HandlerImplementsInterfaceRule>
 */
final class HandlerImplementsInterfaceRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HandlerImplementsInterfaceRule($this->createReflectionProvider());
    }

    public function test_silent_when_command_handler_implements_interface(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateGood/CreateGoodHandler.php'],
            []
        );
    }

    public function test_fires_when_command_handler_missing_interface(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateBadMissingInterface/CreateBadMissingInterfaceHandler.php'],
            [
                [
                    'Class Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadMissingInterface\CreateBadMissingInterfaceHandler '
                    . 'is in Commands namespace but does not implement CommandHandlerInterface. '
                    . 'Either add the `implements` clause or move the class out of the Commands/ directory.',
                    14,
                ],
            ]
        );
    }

    public function test_fires_when_query_handler_missing_interface(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Queries/GetBadMissingInterface/GetBadMissingInterfaceHandler.php'],
            [
                [
                    'Class Tests\Unit\Phpstan\Rules\Fixtures\Queries\GetBadMissingInterface\GetBadMissingInterfaceHandler '
                    . 'is in Queries namespace but does not implement QueryHandlerInterface. '
                    . 'Either add the `implements` clause or move the class out of the Queries/ directory.',
                    15,
                ],
            ]
        );
    }
}

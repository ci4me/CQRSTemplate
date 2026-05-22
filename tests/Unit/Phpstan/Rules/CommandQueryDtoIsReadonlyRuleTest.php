<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\CommandQueryDtoIsReadonlyRule;

/**
 * E05.5 — exercises CommandQueryDtoIsReadonlyRule against two fixtures:
 * a `final readonly` Command DTO (rule MUST stay silent) and a Command
 * that is only `final` (rule MUST fire).
 *
 * @extends RuleTestCase<CommandQueryDtoIsReadonlyRule>
 */
final class CommandQueryDtoIsReadonlyRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new CommandQueryDtoIsReadonlyRule();
    }

    public function test_silent_when_command_is_final_readonly(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateGood/CreateGoodCommand.php'],
            []
        );
    }

    public function test_fires_when_command_dto_is_not_readonly(): void
    {
        $line = $this->classLine(__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateBadDtoMutable/CreateBadDtoMutableCommand.php');

        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateBadDtoMutable/CreateBadDtoMutableCommand.php'],
            [
                [
                    'Class Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadDtoMutable\CreateBadDtoMutableCommand '
                    . 'is a Command/Query DTO but is not declared `final readonly class`. '
                    . 'Command/Query DTOs must be immutable; mark the class `final readonly`.',
                    $line,
                ],
            ]
        );
    }

    public function test_silent_when_query_is_final_readonly(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Queries/GetGood/GetGoodQuery.php'],
            []
        );
    }

    /**
     * Locate the `class` keyword line in a fixture file. Keeps the
     * expected-line assertion robust against future docblock changes
     * in the fixtures.
     */
    private function classLine(string $file): int
    {
        $content = file_get_contents($file);
        self::assertNotFalse($content);
        $lines = explode("\n", $content);
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(?:final\s+)?(?:abstract\s+)?(?:readonly\s+)?(?:final\s+)?class\s+\w+/', $line) === 1) {
                return $i + 1;
            }
        }
        self::fail("No class declaration found in {$file}");
    }
}

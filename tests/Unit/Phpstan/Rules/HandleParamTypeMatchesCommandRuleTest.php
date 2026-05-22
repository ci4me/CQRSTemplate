<?php

declare(strict_types=1);

namespace Tests\Unit\Phpstan\Rules;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\HandleParamTypeMatchesCommandRule;

/**
 * E05.5 — exercises HandleParamTypeMatchesCommandRule against two
 * fixtures: a well-formed handler whose `@implements` generic names
 * its sibling Command (rule MUST stay silent) and a handler whose
 * generic points at a class that doesn't exist next to it (rule MUST
 * fire with `cqrs.handlerGenericMismatch`).
 *
 * @extends RuleTestCase<HandleParamTypeMatchesCommandRule>
 */
final class HandleParamTypeMatchesCommandRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new HandleParamTypeMatchesCommandRule($this->createReflectionProvider());
    }

    public function test_silent_when_generic_matches_sibling_command(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateGood/CreateGoodHandler.php'],
            []
        );
    }

    public function test_fires_when_generic_points_at_nonexistent_command(): void
    {
        $handlerFile = __DIR__ . '/Fixtures/app/Domain/FixtureDomain/Commands/CreateBadGenericMismatch/CreateBadGenericMismatchHandler.php';
        $line = $this->classLine($handlerFile);

        $this->analyse(
            [$handlerFile],
            [
                [
                    'Handler Tests\Unit\Phpstan\Rules\Fixtures\Commands\CreateBadGenericMismatch\CreateBadGenericMismatchHandler '
                    . 'declares `@implements CommandHandlerInterface<NonExistentCommand, ...>` but no class named NonExistentCommand '
                    . 'exists in the same Commands directory (' . dirname($handlerFile) . ').',
                    $line,
                ],
            ]
        );
    }

    public function test_silent_when_query_generic_matches_sibling(): void
    {
        $this->analyse(
            [__DIR__ . '/Fixtures/app/Domain/FixtureDomain/Queries/GetGood/GetGoodHandler.php'],
            []
        );
    }

    /**
     * See CommandQueryDtoIsReadonlyRuleTest::classLine — same helper.
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

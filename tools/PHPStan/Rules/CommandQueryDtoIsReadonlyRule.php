<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Static enforcement of the Command/Query DTO immutability contract.
 *
 * Any class file in:
 *
 *   app/Domain/{Domain}/Commands/{Operation}/   named *Command.php
 *   app/Domain/{Domain}/Queries/{Operation}/    named *Query.php
 *
 * MUST be declared `final readonly class`.
 *
 * Rationale: Commands and Queries are pure DTOs — once a controller
 * hands a Command to the bus, no middleware (logging, audit, transaction)
 * may mutate its fields. Marking the class `final readonly` is the only
 * PHP-level guarantee. Forgetting either modifier is silently fine for
 * the compiler but a latent correctness bug at the bus seam.
 *
 * Scope (deliberately narrow):
 *   - Skips abstract base classes (none exist today; defensive only).
 *   - Skips files NOT named *Command.php / *Query.php.
 *   - Skips files outside the app/Domain/.../Commands|Queries/{Op}/ pattern.
 *
 * @implements Rule<Node\Stmt\Class_>
 * @package Tools\PHPStan\Rules
 */
final class CommandQueryDtoIsReadonlyRule implements Rule
{
    /**
     * Get the parser node type this rule processes.
     */
    public function getNodeType(): string
    {
        return Class_::class;
    }

    /**
     * Run the rule against a class node.
     *
     * @param Class_ $node The class node being analysed.
     * @return array<int, \PHPStan\Rules\RuleError> Empty when the rule does not fire.
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name === null) {
            return [];
        }

        // Abstract bases are exempt — the readonly contract applies to
        // the concrete DTO leaves that actually cross the bus.
        if ($node->isAbstract()) {
            return [];
        }

        $file = $scope->getFile();
        $expected = $this->expectedSuffixForFile($file);
        if ($expected === null) {
            return [];
        }

        if (!str_ends_with($file, $expected . '.php')) {
            return [];
        }

        if ($node->isFinal() && $node->isReadonly()) {
            return [];
        }

        $className = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $message = sprintf(
            'Class %s is a Command/Query DTO but is not declared `final readonly class`. '
            . 'Command/Query DTOs must be immutable; mark the class `final readonly`.',
            $className
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('cqrs.dtoNotFinalReadonly')
                ->build(),
        ];
    }

    /**
     * Decide whether the file is a Command or Query DTO file in scope.
     *
     * Returns 'Command' or 'Query' (suffix to match before .php),
     * or null when the rule should skip this file.
     */
    private function expectedSuffixForFile(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);

        if (preg_match('#/app/Domain/[^/]+/Commands/[^/]+/[^/]+\.php$#', $normalized) === 1) {
            return 'Command';
        }

        if (preg_match('#/app/Domain/[^/]+/Queries/[^/]+/[^/]+\.php$#', $normalized) === 1) {
            return 'Query';
        }

        return null;
    }
}

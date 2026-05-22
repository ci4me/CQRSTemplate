<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Static enforcement of the CQRS handler-interface contract.
 *
 * Fires on any concrete (non-abstract) class file that:
 *
 *   1. Lives under  app/Domain/{Domain}/Commands/{Operation}/   AND
 *      is named *Handler.php                                    AND
 *      does NOT implement {@see \App\Domain\Shared\Bus\CommandHandlerInterface}
 *      (directly OR via extending AbstractCommandHandler).
 *
 *   2. The Queries-side equivalent.
 *
 * Rationale: E05 enforces the contract at *bus registration* runtime —
 * a typo'd handler class only fails on the first dispatch, which may be
 * weeks after the typo lands. This rule shifts the failure left to
 * `composer phpstan` so the cloner sees the violation on the same
 * commit that introduced it.
 *
 * Scope (deliberately narrow):
 *   - Skips abstract classes (the AbstractCommandHandler base lives in
 *     Shared/Bus, not under Commands/, but defensive anyway).
 *   - Skips files NOT named *Handler.php (utilities co-located with a
 *     command directory don't have to implement the interface).
 *   - Skips classes located OUTSIDE app/Domain/{Domain}/{Commands|Queries}/
 *     (the rule only watches the CQRS slice directories; auth handlers
 *     under app/Infrastructure/Auth/Commands/ are intentionally NOT in
 *     scope here).
 *
 * @implements Rule<Node\Stmt\Class_>
 * @package Tools\PHPStan\Rules
 */
final class HandlerImplementsInterfaceRule implements Rule
{
    private const string COMMAND_INTERFACE = 'App\\Domain\\Shared\\Bus\\CommandHandlerInterface';

    private const string QUERY_INTERFACE = 'App\\Domain\\Shared\\Bus\\QueryHandlerInterface';

    /**
     * Construct.
     */
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

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
        if ($node->name === null || $node->isAbstract()) {
            return [];
        }

        $file = $scope->getFile();
        $kind = $this->kindForFile($file);
        if ($kind === null || !str_ends_with($file, 'Handler.php')) {
            return [];
        }

        $className = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $interface = $kind === 'Commands' ? self::COMMAND_INTERFACE : self::QUERY_INTERFACE;

        if ($this->classImplementsInterface($node, $interface)) {
            return [];
        }

        $expected = $kind === 'Commands' ? 'CommandHandlerInterface' : 'QueryHandlerInterface';
        $bucket = $kind;

        $message = sprintf(
            'Class %s is in %s namespace but does not implement %s. '
            . 'Either add the `implements` clause or move the class out of the %s/ directory.',
            $className,
            $bucket,
            $expected,
            $bucket
        );

        return [
            RuleErrorBuilder::message($message)
                ->identifier('cqrs.handlerMissingInterface')
                ->build(),
        ];
    }

    /**
     * Decide whether the file path puts the class in scope.
     *
     * Returns 'Commands' or 'Queries' for an in-scope file, or null
     * when the rule should ignore this file altogether.
     */
    private function kindForFile(string $file): ?string
    {
        $normalized = str_replace('\\', '/', $file);

        if (preg_match('#/app/Domain/[^/]+/Commands/[^/]+/[^/]+\.php$#', $normalized) === 1) {
            return 'Commands';
        }

        if (preg_match('#/app/Domain/[^/]+/Queries/[^/]+/[^/]+\.php$#', $normalized) === 1) {
            return 'Queries';
        }

        return null;
    }

    /**
     * Inspect the AST + parent chain to decide whether the class
     * effectively implements $interface.
     *
     * Path A: `implements` clause carries the interface FQN directly.
     * Path B: `extends` clause names an ancestor that (transitively)
     *          implements the interface — covers AbstractCommandHandler.
     */
    private function classImplementsInterface(Class_ $node, string $interface): bool
    {
        foreach ($node->implements as $implementsName) {
            if (ltrim($implementsName->toString(), '\\') === $interface) {
                return true;
            }

            // The Name node might be unqualified (e.g. `CommandHandlerInterface`)
            // resolved later by use statements. Compare on the short name
            // and fall back to reflection for the full check.
            if ($this->reflectionResolvesToInterface($implementsName->toString(), $interface)) {
                return true;
            }
        }

        if ($node->extends !== null) {
            $parentFqn = $node->extends->toString();
            if ($this->parentReflectionImplements($parentFqn, $interface)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the ReflectionProvider to resolve a (possibly short) name
     * and check whether it equals the wanted interface FQN.
     */
    private function reflectionResolvesToInterface(string $name, string $interfaceFqn): bool
    {
        if (!$this->reflectionProvider->hasClass($name)) {
            return false;
        }

        $reflection = $this->reflectionProvider->getClass($name);

        return $reflection->getName() === $interfaceFqn;
    }

    /**
     * Walk the parent reflection chain and decide whether $interface
     * is reachable from the named parent class.
     */
    private function parentReflectionImplements(string $parentName, string $interfaceFqn): bool
    {
        if (!$this->reflectionProvider->hasClass($parentName)) {
            return false;
        }

        return $this->reflectionProvider->getClass($parentName)->implementsInterface($interfaceFqn);
    }
}

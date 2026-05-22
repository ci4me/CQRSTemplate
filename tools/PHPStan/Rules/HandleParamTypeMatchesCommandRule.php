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
 * Static enforcement of the @implements generic on CQRS handlers.
 *
 * On any concrete class that implements CommandHandlerInterface (directly
 * or via extending AbstractCommandHandler), the class-level docblock must
 * carry:
 *
 *   @implements CommandHandlerInterface<TCommand, TResult>
 *
 * where TCommand names an actually-existing *Command class that sits in
 * the same Commands subdirectory. The mirror rule applies to
 * QueryHandlerInterface / *Query.
 *
 * Why this matters: without the generic, PHPStan widens the handler
 * parameter to `object` and cannot narrow the body. Worse, a copy-pasted
 * handler may carry the WRONG generic (the old command name from the
 * directory it was cloned from). The bus runtime check catches the
 * mismatch eventually, but PHPStan can catch it on the same commit.
 *
 * Scope:
 *   - Concrete (non-abstract) classes only.
 *   - Class must live under app/Domain/{Domain}/{Commands|Queries}/{Op}/.
 *   - Class must implement the corresponding handler interface (we don't
 *     fire on a *Handler file that already failed Rule 1).
 *
 * @implements Rule<Node\Stmt\Class_>
 * @package Tools\PHPStan\Rules
 */
final class HandleParamTypeMatchesCommandRule implements Rule
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

        $interface = $kind === 'Commands' ? self::COMMAND_INTERFACE : self::QUERY_INTERFACE;
        if (!$this->classImplementsInterface($node, $interface)) {
            // Let HandlerImplementsInterfaceRule own that violation; we
            // only validate generic alignment for handlers that already
            // satisfy the interface contract.
            return [];
        }

        $className = $node->namespacedName !== null
            ? $node->namespacedName->toString()
            : $node->name->toString();

        $tCommandShort = $this->extractGenericFromDocComment($node, $kind);
        if ($tCommandShort === null) {
            $expectedShort = $kind === 'Commands' ? 'CommandHandlerInterface' : 'QueryHandlerInterface';

            return [
                RuleErrorBuilder::message(sprintf(
                    'Handler %s implements %s but is missing the '
                    . '`@implements %s<TCommand, TResult>` docblock. Add the '
                    . 'generic so PHPStan can narrow the handle() parameter.',
                    $className,
                    $expectedShort,
                    $expectedShort
                ))
                    ->identifier('cqrs.handlerMissingGeneric')
                    ->build(),
            ];
        }

        $commandsDir = dirname($file);

        if ($this->commandClassExistsInDir($commandsDir, $tCommandShort)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                'Handler %s declares `@implements %s<%s, ...>` but no class named %s exists in the same %s directory (%s).',
                $className,
                $kind === 'Commands' ? 'CommandHandlerInterface' : 'QueryHandlerInterface',
                $tCommandShort,
                $tCommandShort,
                $kind,
                $commandsDir
            ))
                ->identifier('cqrs.handlerGenericMismatch')
                ->build(),
        ];
    }

    /**
     * Decide whether the file is a Commands/Queries handler in scope.
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
     * Whether the AST class effectively implements the handler interface.
     *
     * Direct `implements` carries the interface FQN; `extends` resolves
     * via reflection to cover AbstractCommandHandler-style chains.
     */
    private function classImplementsInterface(Class_ $node, string $interface): bool
    {
        foreach ($node->implements as $name) {
            $fqn = ltrim($name->toString(), '\\');
            if ($fqn === $interface) {
                return true;
            }

            if ($this->reflectionProvider->hasClass($fqn)
                && $this->reflectionProvider->getClass($fqn)->getName() === $interface) {
                return true;
            }
        }

        if ($node->extends !== null) {
            $parent = ltrim($node->extends->toString(), '\\');

            if ($this->reflectionProvider->hasClass($parent)
                && $this->reflectionProvider->getClass($parent)->implementsInterface($interface)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the class-level docblock and pull TCommand out of
     * `@implements (Command|Query)HandlerInterface<TCommand, TResult>`.
     *
     * Returns the unqualified short name (e.g. `CreateCookieCommand`)
     * or null when the annotation is missing or unparseable.
     */
    private function extractGenericFromDocComment(Class_ $node, string $kind): ?string
    {
        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return null;
        }

        $interfaceShort = $kind === 'Commands' ? 'CommandHandlerInterface' : 'QueryHandlerInterface';
        $pattern = '/@implements\s+(?:\\\\?[A-Za-z_][A-Za-z0-9_]*\\\\)*' . preg_quote($interfaceShort, '/') . '\s*<\s*(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)\s*,/';

        if (preg_match($pattern, $docComment->getText(), $matches) !== 1) {
            return null;
        }

        // Strip leading backslash + namespace path; we compare on the
        // short class name against files in the slice directory.
        $raw = ltrim($matches[1], '\\');
        $lastSlash = strrpos($raw, '\\');

        return $lastSlash === false ? $raw : substr($raw, $lastSlash + 1);
    }

    /**
     * Look in the slice directory for a sibling file matching {Name}.php.
     */
    private function commandClassExistsInDir(string $dir, string $shortName): bool
    {
        return is_file($dir . DIRECTORY_SEPARATOR . $shortName . '.php');
    }
}

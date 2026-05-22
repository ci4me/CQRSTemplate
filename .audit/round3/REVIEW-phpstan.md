# Review (phpstan-specialist) — v2 Remediation Plan

## Verdict
**APPROVED-WITH-CHANGES**

The plan correctly identifies the highest-value PHPStan gaps (phpVersion
pin, bus duck-typing, abstract-handler generics, handler-shape narrowing).
It mostly under-specifies the *static-analysis enforcement* of the new
contracts — generics syntax, `@phpstan-require-implements`, baseline
hygiene, and ignore-comment hunt-down are absent from the epic acceptance
gates and need to be added before E05 / E02 / E08 land.

## Strengths
- E02 puts `phpVersion: 80300` in Phase 0 alongside the `docblocks:audit`
  fix and pushes `composer ci` into CI — closes the silent-no-op gate the
  audit calls out (16/F8). Correct value: `^8.3` → `80300` (PHPStan uses
  packed `PHP_VERSION_ID`, i.e. `8 * 10000 + 3 * 100 + 0 = 80300`).
- E05 explicitly states "PHPStan L8 narrows handler types end-to-end (no
  `mixed` from buses)" as an acceptance gate — that is the *right* gate.
- The Phase-0/Phase-1 sequencing (pin first, generics second, migration
  third) is the order PHPStan needs to actually verify each Phase-2 epic.
- E16 correctly bumps `phpVersion` to `80400` *after* `composer.json` —
  pin moves with target.

## Required changes

1. **E05 must specify the generic shape, not just "typed".** The plan
   says `QueryHandlerInterface<TQuery, TResult>` once in T2's prose but
   the epic acceptance gates never require a `@template` declaration.
   Without an explicit template, PHPStan will continue to widen handler
   results to `mixed`. Add the sketch below to the epic body and add an
   assertion `test_query_bus_dispatch_return_type_is_narrowed_by_phpstan`
   (a `\PHPStan\Testing\TypeInferenceTestCase` fixture).

2. **Bus enforcement of the handler interface must use
   `@phpstan-require-implements` on the abstract class AND `instanceof`
   at `register()`.** The plan says "delete `method_exists` duck-typing"
   but does not specify what replaces it. A custom PHPStan rule is
   overkill; the standard idiom is the runtime `instanceof` plus the
   interface contract on the parameter type. See proposed signature
   below. Also: **delete the `/** @phpstan-ignore method.notFound …`
   suppression at `QueryBus.php:96` and the matching one in
   `CommandBus.php` once `register()` is typed.** E05 / E08 must list
   these two ignore-comments by file:line as part of acceptance.

3. **Baseline policy is missing entirely.** No `phpstan-baseline.neon`
   exists today (`ls phpstan*` shows only `phpstan.neon` and the
   bootstrap), which is good. The plan must *forbid creating one* in
   any of E04–E18; or, if a baseline is unavoidable for a destructive
   epic (E09 schema or E12 outbox), require it to be (a) created via
   `--generate-baseline`, (b) committed in the same PR as the offending
   change, and (c) burned down in the next epic. Add this to E02
   acceptance: `phpstan.neon` must not include `phpstan-baseline.neon`
   unless paired with a tracked burn-down issue.

4. **Array-shape adoption is not enforced anywhere.** The plan mentions
   `CookieDTO::fromRow(array)` (E10) but never requires the parameter
   to be typed as `array{id: int, name: string, price_minor: int,
   price_currency: string, …}`. Without the shape annotation, PHPStan
   L8 will complain about `mixed` offset access — the exact pattern
   from the `Read` shape annotation example. Add to E10 acceptance:
   "every `fromRow()` factory carries an `array{…}` shape covering
   every column it reads; PHPStan L8 reports zero `offsetAccess.notFound`
   on the read path." Similarly E09 must require array-shape
   annotations on `CookieRepository::toDomainEntity()` and friends.

5. **Cross-check the existing `ignoreErrors` block in `phpstan.neon`
   when each epic lands.** Today's config silences `argument.templateType`
   in `app/Infrastructure/Bus/Middleware/` (line 128). Once E05 lands
   typed handler interfaces, that suppression may be removable. E05
   acceptance should require *attempting* to remove every middleware-
   path ignore and documenting which (if any) cannot be removed.

6. **`reportUnmatchedIgnoredErrors: false` should flip to `true`
   in E02.** Leaving it `false` means stale ignores rot silently — the
   plan's "hunt down ignore comments" instinct is undermined by the
   config. Single-line change; add to E02.

7. **Generics for the abstract bases must include covariance docs.**
   `QueryHandlerInterface<TQuery of object, TResult>` is fine for queries
   that return a DTO or list; but `GetCookieById` returns
   `?CookieDTO`, so the TResult bound must be widened to admit null
   without falling back to `mixed`. Spell this out in E05.

## Concrete PHPStan config diff (sketch)

```yaml
# phpstan.neon — E02 patch
parameters:
    level: 8
    phpVersion: 80300          # E02 — pin to require.php = ^8.3
    reportUnmatchedIgnoredErrors: true   # E02 — flip from false; rots otherwise
    treatPhpDocTypesAsCertain: true       # tighten narrowing on @var/@param
    paths:
        - app/Domain
        - app/Infrastructure
        - app/Libraries
        - app/Models
        - app/Controllers
        - tests
    # … existing excludePaths + ignoreErrors unchanged …
    # E05 acceptance: attempt removal of the following block; document survivors:
    #   - identifier: argument.templateType, path: app/Infrastructure/Bus/Middleware/
includes:
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    - vendor/phpstan/phpstan-phpunit/extension.neon
    - vendor/phpstan/phpstan-phpunit/rules.neon
    # E05 candidate: vendor/phpstan/phpstan-deprecation-rules/rules.neon
    #   (pairs with #[\Deprecated] in E16 / @deprecated in E09)
```

For E16 (PHP 8.4 bump): change `phpVersion: 80300` → `phpVersion: 80400`
in the same PR that bumps `composer.json`.

## Generic-types sketch for abstract bases

```php
/**
 * @template TCommand of object
 */
interface CommandHandlerInterface
{
    public function handle(object $command): void;
}

/**
 * @template TCommand of object
 * @implements CommandHandlerInterface<TCommand>
 *
 * @phpstan-require-extends AbstractCommandHandler
 */
abstract class AbstractCommandHandler implements CommandHandlerInterface
{
    /** @param TCommand $command */
    abstract protected function handleCommand(object $command): void;

    public function handle(object $command): void
    { /* startTime / try / catch / log — final */ }
}

/**
 * @template TQuery of object
 * @template TResult
 */
interface QueryHandlerInterface
{
    /**
     * @param TQuery $query
     * @return TResult
     */
    public function handle(object $query): mixed;
}

/**
 * @template TQuery of object
 * @template TResult
 * @implements QueryHandlerInterface<TQuery, TResult>
 */
abstract class AbstractQueryHandler implements QueryHandlerInterface
{
    /**
     * @param TQuery $query
     * @return TResult
     */
    abstract protected function handleQuery(object $query): mixed;
}

// Concrete: PHPStan can now narrow ?CookieDTO end-to-end.
/** @extends AbstractQueryHandler<GetCookieByIdQuery, ?CookieDTO> */
final class GetCookieByIdHandler extends AbstractQueryHandler { … }

// Bus signature replacing the duck-typed register():
/**
 * @param class-string                 $commandClass
 * @param CommandHandlerInterface<*>   $handler
 */
public function register(string $commandClass, CommandHandlerInterface $handler): void
```

This kills both `@phpstan-ignore method.notFound` comments (CommandBus
line ~111, QueryBus line 96), removes the `method_exists()` runtime
guards (CommandBus:83, QueryBus:65), and lets PHPStan verify every
`dispatch()` return path without `mixed`.

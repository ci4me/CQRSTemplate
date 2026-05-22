# Review (slevomat-specialist) — v2 Remediation Plan

## Verdict
APPROVED-WITH-CHANGES

## Strengths

- E02 correctly pins `php_version=80300` in `phpcs.xml` AND `phpVersion: 80300`
  in `phpstan.neon`. Single line, blocks PHP 8.4 syntax from landing.
- E02 extends `bin/docblocks-audit` to fail on `\* (\w+)\.$\n\s*\*/` single-word
  stubs — addresses 16/F1 placeholders that Squiz.Commenting today accepts.
- E05 introduces shared `LogSampler` (`random_int`) — removes the 3× `mt_rand`
  duplication that `SlevomatCodingStandard.Functions.StrictCall` would call out
  once enabled for `mt_*` family.
- Plan correctly leaves the documented `RequireAbstractOrFinal` exclude for
  `EventDispatcher` (testing mock) untouched.
- E04 / E07 / E08 add `implements \Stringable` and final-by-default to new
  events, aligning with `RequireAbstractOrFinal` already enforced today.

## Required changes

1. **Refresh the size-cap `<exclude-pattern>` baseline as PRs land.** `phpcs.xml`
   currently excludes 9 Cookie files from `Functions.FunctionLength` and 1 from
   `Classes.ClassLength` (`CookieRepository`). Epics E08 (handlers ≤20 LoC), E10
   (CookieDTO consolidation), E11 (`CookieRepository` ≤250 LoC) make those
   excludes obsolete. The plan never says **"remove the exclude-pattern entries
   after the methods/classes shrink"**. Add an acceptance gate per epic:
   "delete the matching `<exclude-pattern>` line in `phpcs.xml` and re-run
   `composer phpcs` to prove the cap holds." Otherwise the cap silently keeps
   ignoring the very files the epic just fixed.

2. **E07 abstract bases will trip `SlevomatCodingStandard.Functions.UnusedParameter`.**
   `AbstractCommandHandler::withLogging(string $commandName, array $context,
   callable $body)` and `AbstractQueryHandler` ship empty/template hook methods
   (e.g. `protected function onBeforeHandle(object $command): void {}`). The
   ruleset enables `UnusedParameter`. Plan must either (a) declare these methods
   `abstract` so the rule skips them, or (b) add `@phpcsSuppress
   SlevomatCodingStandard.Functions.UnusedParameter` annotations. Currently
   silent — they will fail `composer phpcs` the moment they ship.

3. **E15 docblocks rewrite must not regenerate `* Method.` stubs.**
   `bin/docblocks-generate` is the current source of the 26 placeholders.
   E02 patches the audit regex but does NOT patch the generator. If a future
   contributor reruns `bin/docblocks-generate` after E15, the audit will then
   fail. Add an explicit step to E02: **either** patch
   `bin/docblocks-generate` to emit the `@todo Auto-generated docblock`
   marker (the audit already greps for it), **or** delete the generator
   entirely (it has produced the placeholder backlog the audit now rejects).

## phpcs.xml diff (sketch)

```xml
<!-- E02: pin PHP target -->
<config name="php_version" value="80300"/>

<!-- E05: add a rule that detects mt_rand / mt_getrandmax (random.* family).
     Slevomat's StrictCall covers `array_search` etc; add ForbiddenFunctions
     for the non-deterministic samplers we just replaced. -->
<rule ref="Generic.PHP.ForbiddenFunctions">
    <properties>
        <property name="forbiddenFunctions" type="array">
            <element key="mt_rand" value="random_int"/>
            <element key="mt_getrandmax" value="null"/>
        </property>
    </properties>
</rule>

<!-- E08/E11: remove these lines once handlers + repo are split -->
- <exclude-pattern>app/Domain/Cookie/Commands/*/Handler.php</exclude-pattern>
- <exclude-pattern>app/Domain/Cookie/Repositories/CookieRepository.php</exclude-pattern>

<!-- E07 bases: silence UnusedParameter on hook stubs OR declare abstract -->
<rule ref="SlevomatCodingStandard.Functions.UnusedParameter">
    <exclude-pattern>app/Domain/Shared/Handlers/Abstract*Handler.php</exclude-pattern>
</rule>

<!-- E16 (Phase 4): bump to 80400 when composer.json bumps -->
<config name="php_version" value="80400"/>
```

## Auto-fix opportunities (phpcbf) per epic

| Epic  | Files touched                                | phpcbf can autofix?                                                  |
|-------|----------------------------------------------|----------------------------------------------------------------------|
| E02   | placeholder docblocks (26 sites)             | NO — prose; manual rewrite                                           |
| E04   | 5 events extend AbstractDomainEvent          | PARTIAL — `AlphabeticallySortedUses` + `UseSpacing` autofix          |
| E05   | new abstract handlers, bus changes           | PARTIAL — `TrailingArrayComma`, `UselessParentheses` autofix         |
| E07   | Cookie entity adds softDelete/restore        | NO — semantic; manual                                                |
| E08   | 7 handlers shrink                            | YES (cosmetics) — `EarlyExit`, `UselessIfConditionWithReturn` autofix|
| E09   | Money VO + migration                         | PARTIAL — type-cast + null-coalesce autofixable                      |
| E10   | CookieDTO consolidation                      | PARTIAL — `ModernClassNameReference`, `UseSpacing` autofix           |
| E11   | CookieRepository split                       | PARTIAL — extract is manual; `use` ordering autofixable              |
| E13   | Controller `final` + ctor injection          | PARTIAL — `RequireAbstractOrFinal` autofixes the `final` keyword     |
| E14   | views                                        | N/A — views excluded from phpcs.xml:14                               |
| E15   | docs                                         | N/A — markdown                                                       |
| E16   | PHP 8.4 syntax                               | NO — semantic refactor                                               |
| E17   | `#[\Override]`, Stringable, typed const      | PARTIAL — `NullableTypeForNullDefaultValue` autofix; rest manual     |
| E18   | new tests                                    | YES — bracket/spacing autofixable                                    |

**Recommendation:** every epic PR template should run `vendor/bin/phpcbf` as
step 1 of CI (auto-applied as a commit) before `vendor/bin/phpcs` verifies
zero violations. Plan doesn't mention this — add it to the per-epic acceptance
gate.

## One additional concern (not blocking)

`SlevomatCodingStandard.Commenting.UselessFunctionDocComment` is **not enabled
today**. The plan fixes 26 placeholders manually (E02) but doesn't add the
sniff that would prevent regression. Consider adding it after E02 lands so
future placeholder docblocks fail `composer phpcs` directly, not only the
custom `bin/docblocks-audit` regex.

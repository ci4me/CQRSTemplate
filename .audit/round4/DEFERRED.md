# Round-4 Execution — Deferred Items Ledger

**Date:** 2026-06-05 · **Executed on:** `stabilization/erp-foundation`
**Context:** Round-4 remediation (R0–R4) was executed autonomously in one
session per `.audit/round4/CONSOLIDATED-PLAN.md`. Everything below was
**deliberately deferred** — listed here so it cannot silently vanish into
"closed" status the way the round-3 phantom merges did.

## Disposition of the open PR stack (#32, #34–#42)

Round-4 re-implemented the load-bearing content of E04/E05(part)/E07(part)/
E08(part)/E10(part)/E11(part)/E12(part)/E18(logger-isolation) directly on
the branch. **Recommendation: close PRs #32, #34–#42 as "folded into
round-4"** after diffing each against the branch for any nugget not
re-implemented (notably: E05.5 PHPStan custom rule, full abstract handler
bases, CookieChangeSet typed snapshot from PR #35).

## Deferred — architectural

| Item | Origin | Notes |
|---|---|---|
| E05 full abstract bases (`AbstractCommandHandler`/`AbstractQueryHandler` + ClockInterface/SystemClock/LogSampler) | E05/E08 | Marker interfaces + Closure-typed buses landed instead. The remaining value: unified failure-log shape, slow-query→warning, `random_int` sampling, timer source, domain-from-class. Touches all 7 Cookie + 9 User query/command handlers. |
| E05.5 PHPStan rule (restrict `AggregateHydrator::key()` to repositories) | E06 docblock promise | Convention-only today; promised in Cookie.php docblock. |
| E13 — DI overhaul, controller refactor, ONE middleware home (app/Filters is empty while 3 infra dirs hold middleware) | round-3 plan | Untouched in round 4. |
| E16 — PHP 8.4 bump (composer ^8.4, phpVersion 80400, asymmetric visibility, Randomizer) | round-3 plan | Local runtime is 8.4; composer.json still ^8.3 (8.4-compatible). Bump is a deliberate compatibility decision, not a code fix. |
| E17 — 8.3 idiom polish (#[\Override], Stringable, final on Controller/Model) | round-3 plan | Cosmetic; skipped under token budget. |
| E20 — User domain convergence (AggregateRoot contract, version/locking, outbox, read-side port returning UserDTO) | round-4 audits | **Cookie is canonical; User is the cautionary tale.** Do NOT clone User patterns. Full convergence is multi-day; quarantine was rejected because auth flows depend on User. |
| Keyset/cursor pagination variant on the read port | read-side audit | Offset pagination + MAX_PAGE ceiling landed; keyset demo for ERP-scale lists still missing. |
| Command-bus idempotency middleware (idempotency_keys exists at HTTP edge only) | write-side audit | E12's `event_uuid` UNIQUE covers the outbox side. |
| Identity VOs (CookieId etc.) | VO audit | Strategic; own epic if adopted. |
| Command field naming standard (`id` + `actor`; RestoreCookieCommand still uses `cookieId`/`restoredBy`) | write-side audit | Rename ripples through controller + provider + tests. |

## Deferred — quality/platform

| Item | Origin | Notes |
|---|---|---|
| Shared VO kernel error-code contract (Currency/DocumentNumber/Actor/... throw uncoded `\InvalidArgumentException`) | VO audit | UserName fixed as the pattern; sweep the kernel + User VOs the same way. |
| Duplicate Email VO consolidation (Shared vs User, already diverged) | VO audit | Pick Shared, parameterise the error code. |
| Dual logging stacks (CI4 `log-*.log` + Monolog `app-*.json`) | sweep | Test isolation + threshold landed; collapsing to Monolog-only touches framework internals. |
| app/Commands + app/Helpers in the COVERAGE denominator (they're in PHPStan now) | sweep | Needs relay/worker tests first or the 90% gate fails. |
| `docblocks:audit` scope widening beyond app/Domain/Cookie | toolchain audit | Widen incrementally (Shared → User → Infrastructure). |
| One real projection wired through ProjectionRegistry (or formally retire the seam) | read-side audit | RebuildProjections seam kept with documented PHPStan ignores. |
| TenantContext non-nullable end-to-end | E19 | Tests + numbering + IntegrationTestCase now exercise tenancy; constructor-level non-nullability across all repos is the follow-up. |
| `reportUnmatchedIgnoredErrors: true` flip in phpstan.neon | E02 follow-up | Blocked on E05/E08 full landing per the inline TODO. |
| E15 docs:cookie-sync CI guard + full COMPLETE_FILE_INVENTORY regeneration | round-3 plan | Round-4 added an addendum to the inventory; the generator/CI guard remains open. |

## Re-audit trigger

Run a focused 1-agent re-audit of the six core dimensions before cloning
the first real ERP domain, per CONSOLIDATED-PLAN §Verification.

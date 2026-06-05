# Round-4 Execution — Deferred Items Ledger

**Date:** 2026-06-05 · **Executed on:** `stabilization/erp-foundation`
**Context:** Round-4 remediation (R0–R4) was executed autonomously in one
session per `.audit/round4/CONSOLIDATED-PLAN.md`. Everything below was
**deliberately deferred** — listed here so it cannot silently vanish into
"closed" status the way the round-3 phantom merges did.

## Disposition of the open PR stack (#32, #34–#42)

Round-4 re-implemented the load-bearing content directly on the branch.
**Recommendation: close every PR below as "folded into round-4"** (file
lists diffed against the tree on 2026-06-05; per-PR verdicts follow). The
stack is cumulative — later PRs carry earlier branches' files — so each
row's "NOT reclaimed" column is the only content unique to that PR.

| PR | Landed in round-4 | NOT reclaimed (lives only in the PR branch) |
|---|---|---|
| #32 (E04) | Envelope (eventId/occurredAt/actorId), 5 events extended, writer stamps event_uuid | **CookieChangeSet** typed snapshot (+tests); `aggregateType`/`aggregateId` ON the envelope; EventDispatcher tweaks |
| #34 (E05) | Domain-owned marker interfaces on all 21 handlers; Closure-typed buses | **Abstract Command/Query handler bases, ClockInterface/SystemClock, LogSampler** (+tests) — largest deferred item |
| #35 (E07) | markDeleted/restore/recordCreation on aggregate; repo single drain; NOT_DELETED code | **CookieActivated/CookieDeactivated events** (activate()/deactivate() still raise nothing); **StockChangeReason enum** (string reasons landed); CookieSnapshot VO; CookieStateAssertions split |
| #36 (E08) | Restore parity; handler/dispatcher decoupling; pagination guards | Handler migration onto the abstract bases (blocked on #34 content) |
| #37 (E05.5) | (the markers the rules enforce are in-tree) | **Three custom PHPStan rules + fixtures** under tools/PHPStan — natural next step, re-cut against new base |
| #38 (E12.5) | Outbox-side dedup (event_uuid UNIQUE) + markDelivered-in-tx | **ProcessedEventStore** (at-most-once listeners) + migration + flow tests |
| #39 (E17) | — | Entire idiom polish (cosmetic) |
| #40 (E15) | Inventory round-4 addendum (different content; this PR's docs are stale now) | PROJECTIONS.md; scaffolding-skill rewrite; **bin/docs-cookie-sync CI guard** |
| #41 (E11) | existsByName live-only; LIKE prefix+escape; version-guarded delete/restore | **purge()** hard-delete; **fromTrusted()** reconstitution on CookieName/CookiePrice; write-port read-concern split |
| #42 (E18) | Logger isolation (re-implemented); migration dropKey fix; CookieStockTest | **deptrac LoggerFactory-ban rule**; ErrorCodes/PriceFormatter/CookieFactory test backfill; sleep() removal |

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

## MySQL-lane verification status (2026-06-05)

- **Migrations: VERIFIED on real MySQL 8.0.36.** A local throwaway container
  (native-auth, default group repointed) ran the full set **up → down → up
  = 0/0/0**, including the round-4 destructive migrations
  `AddCookiesNameIndex` and `HardenEventOutboxTable` (information_schema
  index discovery + `ALTER TABLE MODIFY` + `event_uuid` UNIQUE + lease/
  tenant columns). The MySQL-only paths execute and reverse cleanly.
- **PHPUnit-on-MySQL: NOT verified locally** — the `database.tests` group
  could not be repointed at the container from a plain shell (phpunit.xml.dist's
  `<env>` block governs that group; GitHub CI overrides it via `$GITHUB_ENV`).
  All 1130 tests pass on SQLite; the MySQL run failed only with
  "Unable to connect to the database" (a harness-wiring issue, identical
  with and without the auth fix), not a code defect.
- **Authoritative MySQL run = GitHub CI**, once Actions is re-enabled on the
  `ci4me` account (currently disabled account-side — see env-blockers memory).
  The CI trigger fix (commit `1a8cef4`) makes it run automatically on the
  next push to this branch.

## Re-audit trigger

Run a focused 1-agent re-audit of the six core dimensions before cloning
the first real ERP domain, per CONSOLIDATED-PLAN §Verification. The round-4
self-re-audit already CONFIRMED 9/10 dimensions and caught two introduced
defects (Money INT_MIN guard, CookieCreated actorId) — both fixed in `f772f48`.

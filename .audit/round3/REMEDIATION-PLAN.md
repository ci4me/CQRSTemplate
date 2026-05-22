# Round 3 Remediation Plan — Cookie as Reference Template (v2)

**Companion to:** [`CONSOLIDATED-REPORT.md`](./CONSOLIDATED-REPORT.md) (v2)
**Date:** 2026-05-22
**Execution model:** GitHub epics → one PR per epic (split when > 300
LoC) → autonomous specialist coding via parallel delegation → human
reviews PR.
**Definition of done:** every finding from every slice resolved or
explicitly allocated to a numbered phase-2 / phase-3 / phase-5 epic.
Cookie passes `composer check` on both SQLite and MySQL test lanes.
Scaffolding skill, file inventory, and CLAUDE.md updated to match. MySQL
CI lane green. `composer ci` (incl. `docblocks:audit`, `deptrac`) runs in
CI on every PR. `/add-domain Foo` produces a domain that itself passes
`composer check` against an empty DB.

---

## Critical constraint (USER-LEVEL — DO NOT VIOLATE)

The plan **allocates every single finding** from the 18 audit slices to a
numbered epic. **There is no "out of scope" exclusion bucket.** If a
finding is genuinely later-phase, it is allocated to an explicit "Phase 4:
PHP 8.4 adoption" or "Phase 5: Polish & long-tail" epic — never dropped.

The receipt is the **Finding-to-Epic Matrix** at the bottom of this file:
242 raw findings (the canonical inventory in `CONSOLIDATED-REPORT.md` is
~140, but the matrix is over RAW findings so duplicates are explicitly
allocated alongside their canonicals). Reviewers should grep the matrix
to verify any specific F# they care about is allocated.

---

## Sequencing principle

Three nested constraints govern the order.

**(1) Foundation-first.** The 18 audit slices repeatedly find the same
root cause expressed in different layers: handlers reinvent boilerplate
because there is no base; events disagree because there is no
`AbstractDomainEvent`; the read-side ships two parallel DTOs because there
is no `ReadDTOInterface`; the outbox loses messages because there is no
`event_uuid` UNIQUE; the MySQL connection is unpinned because there is no
`sessionVariables` block. Fixing the bases first eliminates ~60 % of the
per-slice findings without touching any slice code; the per-slice
cleanups then become small, safe diffs that consume the new bases.

**(2) Unblockers strictly first.** Three Phase-0 epics are time-critical
because every downstream verification depends on them: (E01) MySQL CI
lane — until tests actually run against MySQL, every claim about
optimistic locking, NULL-uniqueness, collation, and outbox truncation in
the rest of the plan is unverifiable; (E02) PHPStan/PHPCS phpVersion pin
+ docblocks:audit gate fix — until these gates work, every Phase-1+
landing can silently regress; (E03) MySQL connection envelope (`sql_mode`,
isolation, charset, ROW_FORMAT) — without this, the outbox-hardening epic
(E12) and the multi-currency-schema epic (E09) have unpinned semantics.

**(3) Destructive vs cosmetic + documentation-last.** The multi-currency
schema change (E09) is destructive — it changes the `cookies.price`
column, breaks existing seeded data, and requires a migration rehearsal.
The outbox-table change (E12) is also destructive — widens `status`, adds
`event_uuid` UNIQUE, adds lease columns. Both must land **before** the
read-side DTO consolidation (E10) which decides what to expose at the
boundary. Documentation (E15) lands **last** because it documents the
post-remediation Cookie; regenerating earlier would produce a second
stale snapshot.

The MySQL CI lane (E01) is on the **critical path** for everything that
touches schema, repository, or outbox (E09, E11, E12, E18) because the
SQLite default cannot validate those claims.

---

## Phase plan

- **Phase 0 — Unblockers (immediate, ~1 week, 1 contributor)**
  Fixes that prevent the rest of the plan from being verifiable.
  Epics: E01 (MySQL CI lane + `phpunit.xml.dist`), E02 (PHPStan phpVersion
  pin + docblocks:audit fix + CI wiring), E03 (Database.php
  sessionVariables).
- **Phase 1 — Foundation (~2 weeks)**
  Shared bases. Epics: E04 (AbstractDomainEvent + eventId envelope), E05
  (AbstractCommandHandler + AbstractQueryHandler + bus-enforced
  interfaces), E06 (AggregateRootInterface + AggregateHydrator key +
  reconstitute version guard).
- **Phase 2 — Cookie structural fixes (~2 weeks)**
  Epics: E07 (Cookie entity lifecycle mutators + events), E08 (Cookie
  handler migration to abstract bases + RestoreCookieHandler parity),
  E09 (Multi-currency: CookiePrice bounds via Currency.decimals;
  DECIMAL→price_minor migration), E10 (Read-side consolidation:
  CookieDTO + ReadDTOInterface + MoneyFormatter), E11 (Repository
  hygiene), E12 (Outbox table hardening + claim semantics).
- **Phase 3 — Surface polish & docs (~1.5 weeks)**
  Epics: E13 (Provider DI overhaul + auth filter + Controller),
  E14 (Views: i18n + view-DTO alignment + can() + partials),
  E15 (Documentation catch-up: SKILL.md + inventory + CLAUDE.md +
  PROJECTIONS.md + docs:cookie-sync CI guard).
- **Phase 4 — PHP 8.4 bump & forward-looking (~1 week)**
  Epic E16 (composer.json + phpstan + phpcs bump to 8.4; asymmetric
  visibility on `$id`/`$version`; `Random\Randomizer`; `#[\Deprecated]`;
  `array_any`; `#[\SensitiveParameter]`; `mb_trim()`; native enums for
  StockChangeReason / QueryLoggingLevel).
- **Phase 5 — Coverage & polish (~1 week)**
  Epics: E17 (PHP 8.3 idiom polish: `#[\Override]`, `Stringable`, final
  on Controller+Model, typed const on CookieName, `hrtime` standardisation,
  `array_map` first-class callable), E18 (Coverage close: CookieStock +
  PriceFormatter + ErrorCodes + CookieAccessors + CookieFactory tests;
  MySQL-conditional integration tests; final long-tail mop-up of every
  remaining LOW/INFO finding from slices 06/09/10/11/12/13/15/16/18).

**Total: 18 epics, ~22 PRs, ~5 weeks single-contributor / ~3 weeks
two-contributor.**

---

## Epics

### E01 — Phase 0 — MySQL CI lane + phpunit.xml.dist + force=SQLite removal
- **Phase:** 0
- **Why now:** Until tests run against MySQL, every claim about optimistic
  locking, NULL-uniqueness, `affectedRows()` semantics, collation,
  composite-UNIQUE, JSON, FK actions, FOR UPDATE SKIP LOCKED, and outbox
  truncation in this plan is unverifiable. Slice 13 F1 and slice 18 F-T1
  are the canonical statements; nothing in Phase 1+ can verify itself
  without this.
- **Findings closed (canonical slice/F#):** `13/F1, 13/F2, 13/F17,
  18/F-T1, 18/F-T2`
- **Files touched (estimate):** ~6 files
  - `phpunit.xml.dist` — drop `force="true"` from `database.tests.DBDriver`;
    add `<group name="mysql-only">`.
  - **New CI workflow file:** `.github/workflows/mysql.yml` (or expand
    existing `.github/workflows/ci.yml`) running `composer ci` against a
    MySQL 8 service container.
  - `app/Config/Database.php` — second `tests-mysql` connection group.
  - `tests/Support/IntegrationTestCase.php` — detect lane via env, skip
    mysql-only group on SQLite.
  - `.env.example` — document the `tests-mysql` group.
  - `tests/Integration/Repositories/CookieRepositoryTest.php` — move
    eight `createMock(CookieModel)` methods to
    `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php`;
    remove class-level `#[AllowMockObjectsWithoutExpectations]`.
- **Owner agents (parallel delegation):** `test-specialist` +
  `codeigniter4-specialist` + `phpstan-specialist`.
- **Acceptance gates:**
  - `composer test` green on both SQLite (local default) and MySQL (CI lane).
  - `tests/Integration/.../CookieRepositoryTest.php` is purely real-DB.
  - The 8 mocked methods live in the new unit-test class.
  - `phpunit.xml.dist` no longer carries `force="true"`.
- **PR count estimate:** 1 (large but coherent)
- **Depends on:** —
- **Risk:** **HIGH** — MySQL CI lane is new infrastructure; teach-in cost;
  flaky-test risk on first runs.
- **Rollback plan:** revert the workflow file and keep `force="true"` in
  `phpunit.xml.dist`.

### E02 — Phase 0 — PHPStan/PHPCS phpVersion pin + docblocks:audit fix + CI runs `composer ci`
- **Phase:** 0
- **Why now:** Without `phpVersion: 80300` in `phpstan.neon`, a developer
  on PHP 8.4 can land 8.4-only syntax in code targeting `^8.3` invisibly
  (slice 17 F1-context / G1 risk). Without `composer docblocks:audit` /
  `composer deptrac` actually running in CI, the documentation gate the
  README promises is a no-op (slice 16 F8); 26 placeholder docblocks ship
  today and the audit script greps for an obsolete marker (slice 16 F1).
- **Findings closed (canonical slice/F#):** `16/F1, 16/F4, 16/F5, 16/F6,
  16/F8, 16/F9, 16/F10, 16/F12, 16/F13, 16/F14, 16/F15`
  PHPStan-pin closes are implicit in the gate (no specific F#), but the
  gate enables every other slice-17 fix.
- **Files touched (estimate):** ~10 files
  - `phpstan.neon` — add `parameters.phpVersion: 80300`.
  - `phpcs.xml` — add `<config name="php_version" value="80300"/>`.
  - `bin/docblocks-audit` — extend regex to also fail on
    `\* (\w+)\.$\n\s*\*/` (single-word block).
  - `bin/docblocks-generate` — restore the `@todo Auto-generated docblock`
    marker (alternative to extending the audit regex).
  - `.github/workflows/ci.yml` — replace per-tool steps with `composer ci`
    (or add explicit `composer docblocks:audit` and `vendor/bin/deptrac
    analyse --no-progress` steps).
  - Fix the 26 placeholder docblocks listed in slice 16 F1 (across
    `RestoreCookieHandler`, `CookieDTO`, `CookieRepositoryInterface`,
    `CookieQueryRepositoryInterface`, `CookieRepository`,
    `CookieQueryRepository`, `CookieView`, `CookieStockChangedEvent` /
    `Handler`, `CookieRestoredEvent` / `Handler`,
    `Update/Delete/Restore/CreateCookieCommand`, `UpdateCookieHandler`).
  - `CookieRepository.php:46` — fix `@package` to
    `App\Domain\Cookie\Repositories`.
  - Refresh or delete `.claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`.
  - `CookieSeeder.php` docblock — call out the bypass of optimistic-lock
    version (16/F11) — this is a doc-only ack; the seeder rewrite lands
    in E09.
- **Owner agents:** `claude-code-specialist` + `phpstan-specialist` +
  `slevomat-specialist`.
- **Acceptance gates:**
  - `composer ci` (incl. `docblocks:audit`, `deptrac`) runs in CI on
    every PR.
  - `bin/docblocks-audit` exits 1 on a single-word placeholder docblock.
  - Zero placeholder docblocks remain in Cookie scope.
  - `phpstan.neon` pins `phpVersion: 80300`.
- **PR count estimate:** 1
- **Depends on:** —
- **Risk:** LOW — config + script tightening + cosmetic doc fixes.
- **Rollback plan:** revert the workflow + bin/docblocks-audit changes;
  the docblock fixes are safe to keep.

### E03 — Phase 0 — MySQL connection envelope: sessionVariables (sql_mode + isolation + charset) + DBCollat alignment
- **Phase:** 0
- **Why now:** Without `sessionVariables`, the project takes the server's
  defaults for `sql_mode` (slice 18 F-C1/F-C2/F-C3). The
  `'unsupported_schema'` truncation (slice 18 F-O8) only manifests because
  no strict mode is pinned. The collation mix (Config `DBCollat =
  utf8mb4_general_ci` vs `cookies.name` `utf8mb4_unicode_ci`) silently
  corrupts JOIN behaviour. Phase 1+ epics assume strict semantics.
- **Findings closed (canonical slice/F#):** `18/F-C1, 18/F-C2, 18/F-C3,
  18/F3 (CHECK constraint), 18/F-T2 (DBPrefix)`
- **Files touched (estimate):** ~3 files
  - `app/Config/Database.php` — add the `sessionVariables` array sketched
    in slice 18's "Recommended Database.php patch"; pin `sql_mode`,
    `transaction_isolation = READ-COMMITTED`, `time_zone = +00:00`,
    charset/collation belt-and-braces; align `DBCollat` to
    `utf8mb4_unicode_ci`; set `numberNative = true`; decide and fix
    `DBPrefix` mismatch.
  - `.env.example` — document the SSL block + add a comment that
    `database.tests.DBPrefix` should match Config or be empty.
  - `tests/Support/IntegrationTestCase.php` — ensure session variables are
    applied to the test connection too (verify via
    `SHOW SESSION VARIABLES`).
- **Owner agents:** `codeigniter4-specialist` + `test-specialist`.
- **Acceptance gates:**
  - `composer test` green on the MySQL CI lane (E01) with the new envelope.
  - New assertion: `test_session_variables_pinned_at_connect` checks
    `@@sql_mode`, `@@transaction_isolation`, `@@time_zone`.
  - `composer test` reproduces the
    `'unsupported_schema'`-truncation-rejection-with-strict-mode (write
    test that confirms strict mode rejects the over-length value, BEFORE
    E12 widens the column).
- **PR count estimate:** 1
- **Depends on:** **E01** (need MySQL lane to verify the envelope).
- **Risk:** MEDIUM — changing `sql_mode` mid-flight will surface every
  data-quality bug the codebase has been hiding (ZERO_DATE, full-group-by,
  etc.); the test suite running against the new envelope is the
  forcing function.
- **Rollback plan:** comment out `sessionVariables` block; ship a
  follow-up correction.

### E04 — Phase 1 — AbstractDomainEvent + eventId/occurredAt/actorId envelope
- **Phase:** 1
- **Why now:** Theme T1. Five Cookie events ship five different envelopes;
  no `eventId` / `occurredAt` / `actorId` anywhere. Outbox supports retries
  with backoff (up to 6 attempts) but has no idempotency anchor at the
  event level. Every subsequent epic that touches events (E07 lifecycle,
  E10 read-side, E12 outbox) is cheaper once the base exists.
- **Findings closed (canonical slice/F#):** `01/F12, 03/F1 (event side),
  03/F15, 05/F1, 05/F2, 05/F3, 05/F4, 05/F5, 05/F6, 05/F9, 12/F6, 14/F18,
  15/F11 (partial)`
- **Files touched (estimate):** ~12 files
  - **New:** `app/Domain/Shared/Events/AbstractDomainEvent.php`,
    `app/Domain/Shared/Events/DomainEventInterface.php` (tighten to require
    getters), `app/Domain/Shared/ValueObjects/EventId.php` (UUIDv7).
  - **Edit:** Five Cookie events (`CookieCreatedEvent`,
    `CookieUpdatedEvent`, `CookieDeletedEvent`, `CookieRestoredEvent`,
    `CookieStockChangedEvent`) → extend base. Tighten
    `CookieStockChangedEvent::$cookieId` to non-nullable `int`.
  - **Edit:** `app/Infrastructure/Bus/EventOutboxWriter.php`,
    `EventOutboxRelay.php` (serialize envelope from base fields, dedup by
    `eventId` — full DB-level dedup lands in E12).
  - **Edit:** `tests/Unit/Domain/Cookie/Events/CookieEventsTest.php`
    (test base behaviour once, drop per-event tautologies).
- **Owner agents (parallel delegation):** `cqrs-specialist` +
  `ddd-specialist` + `test-specialist` + `phpstan-specialist`.
- **Acceptance gates:**
  - `composer check` passes (phpcs + phpstan + phpunit + docblocks:audit).
  - 90 % coverage maintained.
  - New assertions: `test_every_cookie_event_extends_abstract_domain_event`,
    `test_event_id_is_uuid_v7_format`,
    `test_occurred_at_is_immutable_in_utc`,
    `test_cookie_stock_changed_event_cookie_id_is_non_nullable`.
- **PR count estimate:** 1
- **Depends on:** —
- **Risk:** LOW — purely additive on the base, mechanical refactor on the
  5 events. Outbox change is the riskiest piece; rehearse on staging.
- **Rollback plan:** revert the base class + the 5 event edits. Outbox
  format-change is forward-compatible (legacy + v1 envelopes already
  supported).

### E05 — Phase 1 — AbstractCommandHandler + AbstractQueryHandler + bus-enforced interfaces
- **Phase:** 1
- **Why now:** Theme T2. Removes ~280 LoC of duplicated boilerplate across
  7 handlers, brings every `handle()` under the 20-line CLAUDE.md cap,
  eliminates the `str_contains` error-code resolver, fixes slow-query
  log-level escalation, structurally prevents next-domain handlers from
  returning entities. Must land before E08 (handler migration) because
  the base is what the migration consumes. Slow-query level + sampling
  policy land naturally on the base (closes 04/F7, 17/F2 sampler).
- **Findings closed (canonical slice/F#):** `03/F3, 03/F4, 03/F5, 03/F6,
  03/F11, 03/F12, 03/F14, 03/F16, 04/F1, 04/F3, 04/F7, 04/F10, 04/F12,
  14/F1, 14/F2, 14/F3, 14/F20, 14/F21, 17/F2 (partial — extracts shared
  LogSampler), 17/P3 (microtime/hrtime standardisation)`
- **Files touched (estimate):** ~15 files
  - **New:** `app/Domain/Shared/Handlers/AbstractCommandHandler.php`,
    `app/Domain/Shared/Handlers/AbstractQueryHandler.php`,
    `app/Domain/Shared/Handlers/CommandHandlerInterface.php` (typed),
    `app/Domain/Shared/Handlers/QueryHandlerInterface.php` (typed
    generic),
    `app/Domain/Shared/Logging/QueryLoggingPolicy.php`,
    `app/Domain/Shared/Logging/QueryLoggingLevel.php` (enum — closes
    17/G9 query side),
    `app/Domain/Shared/Logging/LogSampler.php` (uses `random_int`).
  - **Edit:** `app/Infrastructure/Bus/CommandBus.php`,
    `QueryBus.php` (enforce typed handler interface; delete
    `method_exists` duck-typing).
- **Owner agents:** `cqrs-specialist` + `phpstan-specialist` +
  `clean-code-specialist` + `test-specialist`.
- **Acceptance gates:**
  - PHPStan L8 narrows handler types end-to-end (no `mixed` from buses).
  - New assertions: `test_command_bus_rejects_handler_without_interface`,
    `test_query_handler_logs_slow_at_warning_not_info`,
    `test_query_handler_sampling_uses_random_int`,
    `test_log_sampler_is_uniform_on_zero_to_one`.
- **PR count estimate:** 1
- **Depends on:** —
- **Risk:** LOW — additive; existing handlers continue to work because
  the base is opt-in until E08 migrates them.
- **Rollback plan:** delete the new shared classes; the existing handlers
  are untouched.

### E06 — Phase 1 — AggregateRootInterface + AggregateHydrator key + reconstitute version guard
- **Phase:** 1
- **Why now:** Theme T7 (foundation side). Tightens hydration surface
  before E07 entity lifecycle epic adds new mutators. Adds the marker
  interface that slice 01 F10 calls out and the version-zero guard that
  slice 01 F4 calls out. The hydrator-key pattern is the long-term
  replacement for the `@internal public` discipline (slice 01 F5);
  PHP 8.4 `private(set)` (G1) is a future improvement on top.
- **Findings closed (canonical slice/F#):** `01/F4, 01/F5, 01/F10`
- **Files touched (estimate):** ~5 files
  - **New:** `app/Domain/Shared/Entities/AggregateRootInterface.php`
    (requires `pullEvents`, `peekEvents`, `hasPendingEvents`, `getId`).
  - **New:** `app/Domain/Shared/Entities/EntityInterface.php` (marker).
  - **New:** `app/Domain/Shared/Entities/AggregateHydrator.php` (the "key"
    parameter so `assignId`/`bumpVersion` require a hydrator instance to
    call).
  - **Edit:** `app/Domain/Cookie/Entities/Cookie.php` — implement
    `AggregateRootInterface`; require `version >= 1` in `reconstitute()`;
    `assignId`/`bumpVersion` accept `AggregateHydrator $key`.
  - **Edit:** `CookieRepository.php` — pass the hydrator key when calling
    `assignId`/`bumpVersion`.
- **Owner agents:** `ddd-specialist` + `test-specialist` +
  `phpstan-specialist`.
- **Acceptance gates:**
  - New assertions: `test_reconstitute_rejects_version_zero`,
    `test_bump_version_rejected_without_hydrator_key`,
    `test_assign_id_rejected_without_hydrator_key`.
- **PR count estimate:** 1
- **Depends on:** —
- **Risk:** LOW — additive interface + tightened guards.
- **Rollback plan:** revert the interface + guard; entity is unaffected.

### E07 — Phase 2 — Cookie entity lifecycle: softDelete/restore/activate/deactivate raise events
- **Phase:** 2
- **Why now:** Theme T7. Without `Cookie::softDelete()` /
  `Cookie::restore()` / events on `activate`/`deactivate`, the unified
  dispatch from E04/E08 has nothing to dispatch on those transitions, and
  the repository keeps owning lifecycle. Also closes the
  `assertPersisted` wrong-code (slice 01 F6) + raw `LogicException` in
  `assignId` (slice 14 F7) + entity-vs-events-asymmetry.
- **Findings closed (canonical slice/F#):** `01/F1, 01/F2, 01/F3, 01/F6,
  01/F7, 01/F9, 01/F11, 03/F1 (entity side), 14/F7, 14/F13, 14/F17,
  15/F14`
- **Files touched (estimate):** ~10 files
  - **Edit:** `app/Domain/Cookie/Entities/Cookie.php` — add `softDelete()`,
    `restore()`, raise events from `activate`/`deactivate`, decide
    pre-persist `update()` policy (recommended: `assertPersisted` guard),
    replace `LogicException` in `assignId` with `DomainException::
    invalidState`, swap `assertPersisted` to use new
    `COOKIE_STATE_NOT_PERSISTED = 403` code, harmonise snapshot types,
    fix `getStock()` to return `CookieStock` not int.
  - **New events:** `CookieActivatedEvent`, `CookieDeactivatedEvent` (or
    one `CookieAvailabilityChangedEvent`) extending
    `AbstractDomainEvent` (E04).
  - **Edit:** `ErrorCodes.php` — add `COOKIE_STATE_NOT_PERSISTED`.
  - **Edit:** `CookieRepository::delete` calls `$cookie->softDelete()`;
    `CookieRepository::restore` calls `$cookie->restore()` (drain via
    `pullEvents()`).
  - **Edit:** `CookieAccessors.php` — update `@property` block to match
    new `getStock(): CookieStock` return.
  - **Edit:** unit tests for entity (cover new events + state-machine
    branches).
- **Owner agents:** `ddd-specialist` + `test-specialist` +
  `clean-code-specialist`.
- **Acceptance gates:**
  - New assertions: `test_soft_delete_raises_cookie_deleted_event`,
    `test_restore_raises_cookie_restored_event_and_bumps_version`,
    `test_activate_raises_event_and_is_idempotent`,
    `test_get_stock_returns_value_object`.
- **PR count estimate:** 1
- **Depends on:** **E04**, **E06**
- **Risk:** MEDIUM — entity is the most-imported file in the domain;
  behaviour changes ripple through feature tests.
- **Rollback plan:** revert entity changes; events stay in place but
  unused.

### E08 — Phase 2 — Cookie handler migration to abstract bases + RestoreCookieHandler parity
- **Phase:** 2
- **Why now:** Theme T2 consumption side + slice 03 F2 (wildcard
  handler). Once the bases exist (E05), the 4 command handlers and 3
  query handlers each shrink by 50–70 lines. Restore is rewritten to
  mirror Delete: structured try/catch, `duration_ms`, `DomainException`
  not `\RuntimeException`, camelCase log keys, rename
  `RestoreCookieCommand::$cookieId` → `$id`.
- **Findings closed (canonical slice/F#):** `03/F1 (handler side), 03/F2,
  03/F7, 03/F8 (TOCTOU doc), 03/F9, 03/F10, 03/F12, 03/F13, 03/F14, 03/F15,
  04/F1 (consumption), 04/F2 (GetAllCookies bound), 04/F4 (length cap),
  04/F5 (sort whitelist), 04/F6 (page ceiling), 04/F8, 04/F9, 04/F11 (cache
  hook seam — deferred but documented), 14/F12, 14/F19`
- **Files touched (estimate):** ~14 files
  - **Edit:** 4 command handlers + 3 query handlers.
  - **Edit:** `RestoreCookieCommand` (rename `$cookieId` → `$id`).
  - **Edit:** `GetAllCookiesQuery` add `MAX_RESULTS` cap, fix docblock
    (4/F13).
  - **Edit:** `GetCookiesPaginatedQuery` — length-cap `searchTerm` via
    `mb_substr(..., 0, 100)`; addcslashes for LIKE; document sort
    whitelist; clamp page ceiling.
  - **Edit:** matching unit tests (~7 files).
- **Owner agents:** `cqrs-specialist` + `clean-code-specialist` +
  `test-specialist`.
- **Acceptance gates:**
  - All 7 `handle()` methods ≤ 20 lines.
  - All 4 handler failure-log shapes identical (camelCase keys, same
    fields).
  - New assertions:
    `test_restore_handler_rejects_already_active_with_correct_code`,
    `test_all_command_handlers_emit_identical_failure_log_shape`,
    `test_get_all_cookies_caps_at_max_results`.
- **PR count estimate:** 1 (may split to 2 if diff > 300 LoC)
- **Depends on:** **E05**
- **Risk:** MEDIUM — touches every command/query handler.
- **Rollback plan:** revert per-handler; bases are unaffected.

### E09 — Phase 2 — Multi-currency: CookiePrice bounds + DECIMAL→price_minor migration + seeder rewrite
- **Phase:** 2
- **Why now:** Theme T3. Destructive schema change with seeded-data
  implications; must land before E10 (read-side DTOs) so the consolidated
  DTO ships with the final money shape.
- **Findings closed (canonical slice/F#):** `02/F1, 02/F2, 02/F3, 02/F4,
  02/F5, 02/F6, 02/F7, 02/F8, 02/F9, 02/F10, 05/F9, 11/F1, 11/F2, 11/F4,
  11/F5, 11/F6, 11/F9, 11/F10, 11/F11, 11/F12, 11/F13 (LOWER drop), 14/F8,
  14/F9, 14/F14, 15/F2 (migration pair squash), 15/F4, 18/F2, 18/F3,
  18/F4, 18/F-S1, 18/F-S2, 18/F-S3, 18/F-FK1, 18/F-G1 (purge), 18/F-FK2,
  18/F-FK3, 18/F-M1, 18/F-M2, 18/F8`
- **Files touched (estimate):** ~18 files
  - **Edit:** `app/Domain/Cookie/ValueObjects/CookiePrice.php` — make
    `Currency` required at every factory, remove `defaultCurrency()`,
    recompute bounds via `$currency->decimals`, harmonise error codes
    (02/F5), implement `JsonSerializable` (02/F6),
    `implements \Stringable` (also addresses 17/F4 partially),
    rename / dedup `equalsIgnoreCase` (02/F7), remove `getValue(): float`
    (02/F9, 14/F8).
  - **Edit:** `CookieStock.php` — add `MAX_STOCK` overflow guard (02/F4);
    pick encapsulation style aligned with siblings (02/F8); rename
    factory to `of()` or `fromQuantity()` (02/F10).
  - **Edit:** `CookieName.php` — pick one equality semantics (case-
    insensitive normalize on construction), delete `equalsIgnoreCase()`
    (02/F3, 02/F7); add typed const types (17/F1 fix here).
  - **New migration:**
    `2026-05-22-100000_RenameCookiesPriceToMinorUnitsWithCurrency.php`
    (DECIMAL(10,2) → BIGINT price_minor + CHAR(3) price_currency NOT
    NULL); add explicit `ENGINE`/`CHARSET`/`COLLATE`/`ROW_FORMAT`; FK on
    `created_by`/`updated_by`/`deleted_by` (CASCADE/SET NULL decision per
    18/F-FK1, 18/F-FK3); default `version` to `1`; `created_at`/
    `updated_at` `NOT NULL`; add `restored_at`/`restored_by` (18/F-S3);
    add CHECK on `(deleted_at IS NULL) <=> (deleted_by IS NULL)`
    (18/F-S2); add CHECK on `is_active IN (0,1)` (18/F3); make
    `tenant_id` `NOT NULL DEFAULT 0` (18/F4 + 11/F11); add generated
    `name_active_key` column for active-unique enforcement (18/F-S1).
  - **Squash migration:** delete `2026-05-20-200000_CreateCookieReadModelTable.php`
    and `2026-05-21-120000_DropCookieReadModelTable.php` (11/F4, 15/F2,
    18/F-M2). The `.example` projection survives as documentation (see
    E15 PROJECTIONS.md).
  - **Standardise migration filenames** (11/F7, 18/F-M1) — out of scope
    to rename existing; document the convention in the scaffolding
    update (E15).
  - **Edit:** `CookieModel.php` — add `price_minor`/`price_currency`/
    `restored_at`/`restored_by` to `$allowedFields`; drop `LOWER(name)`
    (11/F13); drop `withDeleted()` from `existsByName*` (theme T8); make
    `final` (17/F5 partial, 06/F11); add purge support (18/F-G1).
  - **Edit:** `CookieRepository.php` — persist `price_minor` +
    `price_currency`; reconstitute via `Money::fromMinorUnits`;
    `purge(int $id, Actor $actor)` for GDPR (18/F-G1).
  - **Edit:** `CookieRepositoryInterface.php` — add `purge()`; tighten
    `existsByName(CookieName)` typed signature (06/F17).
  - **Edit:** `CookieSeeder.php` — dispatch `CreateCookieCommand` instead
    of raw `insertBatch` (11/F2, 16/F11); replace
    repeated `date('Y-m-d H:i:s')` with one `$now`.
  - **Edit:** unit + integration tests for `CookiePrice`, `CookieName`,
    `CookieStock`, `CookieModel`, `CookieRepository`,
    `CookieQueryRepository`.
- **Owner agents:** `ddd-specialist` + `codeigniter4-specialist` +
  `test-specialist` + `phpstan-specialist`.
- **Acceptance gates:**
  - `composer check` passes against MySQL (via E01).
  - `php spark migrate --all` clean on fresh DB; `migrate:rollback` clean.
  - Seeder produces rows identical to those produced by
    `CreateCookieCommand`.
  - New assertions:
    `test_cookie_price_factory_requires_explicit_currency`,
    `test_cookie_price_bounds_respect_currency_decimals_jpy`,
    `test_cookie_price_bounds_respect_currency_decimals_bhd`,
    `test_cookie_stock_overflow_guard`,
    `test_repository_persists_price_minor_and_currency`,
    `test_existsByName_releases_soft_deleted_names`,
    `test_composite_unique_rejects_duplicates_when_both_active`,
    `test_purge_removes_row_and_audit_log_survives`.
- **PR count estimate:** 1–2
- **Depends on:** **E01** (MySQL CI lane), **E03** (sql_mode pin); soft
  dep on **E07** (entity lifecycle for the seeder's
  `CreateCookieCommand`).
- **Risk:** **HIGH** — destructive schema change.
- **Rollback plan:** revert the migration via the well-tested
  `migrate:rollback`; the Money VO changes are forward-compatible.

### E10 — Phase 2 — Read-side consolidation: CookieDTO + JsonSerializable + ReadDTOInterface + MoneyFormatter
- **Phase:** 2
- **Why now:** Theme T4. Two parallel DTOs poison every view. Lands after
  E09 so the consolidated DTO carries the final money-shape, and after
  E11 so the read repo's row → DTO factory uses the trusted path.
- **Findings closed (canonical slice/F#):** `04/F9, 07/F1, 07/F2, 07/F3,
  07/F4, 07/F5, 07/F6, 07/F7, 07/F8, 07/F9, 07/F10, 07/F11, 07/F12, 07/F13,
  07/F14, 14/F8 (partial), 14/F10, 14/F11, 14/F15, 15/F10`
- **Files touched (estimate):** ~16 files
  - **Delete:** `app/Domain/Cookie/ReadModels/CookieView.php` +
    `tests/Unit/Domain/Cookie/ReadModels/CookieViewTest.php`.
  - **Edit:** `app/Domain/Cookie/DTOs/CookieDTO.php` — non-nullable `id`;
    convert `isOutOfStock()` method to `bool $outOfStock` field; add
    `$version`, `?string $deletedAt`, `bool $isDeleted`, `bool
    $isAvailable`; add `fromRow(array)`; implement `JsonSerializable`
    with snake_case output; add `summary()` factory.
  - **New:** `app/Domain/Shared/DTOs/ReadDTOInterface.php`.
  - **Move:** `app/Domain/Cookie/Services/PriceFormatter.php` →
    `app/Domain/Shared/Services/MoneyFormatter.php` typed against
    `Money`; route all callers through it; remove the `@deprecated` from
    `CookiePrice::format()` (already deleted in E09).
  - **Edit:** `CookieQueryRepository.php` — call `MoneyFormatter::format`;
    use `CookieDTO::fromRow()`.
- **Owner agents:** `cqrs-specialist` + `codeigniter4-specialist` +
  `test-specialist`.
- **Acceptance gates:**
  - New assertions: `test_cookie_dto_serialises_snake_case_keys`,
    `test_cookie_dto_from_row_produces_identical_state_to_from_entity`,
    `test_money_formatter_localises_currency`.
- **PR count estimate:** 1
- **Depends on:** **E09**, **E11**
- **Risk:** MEDIUM — touches every view (handled in E14).
- **Rollback plan:** restore CookieView and revert the DTO additions; the
  underlying schema is unaffected.

### E11 — Phase 2 — Repository hygiene: trusted reconstitution, LIKE escape, single-statement delete
- **Phase:** 2
- **Why now:** Theme T9 + T8. The 586-LoC `CookieRepository` is the worst
  single class; its bugs are user-visible. Lands after E09 so the trusted-
  reconstitution path knows about `price_minor`+`price_currency`.
- **Findings closed (canonical slice/F#):** `06/F1, 06/F4, 06/F5, 06/F6,
  06/F7, 06/F8, 06/F9, 06/F10, 06/F11, 06/F12, 06/F13, 06/F14 (deferred
  to E09 / acknowledged here), 06/F15, 06/F16, 06/F17, 11/F3, 11/F13,
  14/F6, 14/F16, 14/F19, 15/F9, 15/F15`
- **Files touched (estimate):** ~10 files
  - **New VO factories:** `CookieName::fromTrusted(string)`,
    `CookiePrice::fromTrusted(int $minor, string $currency)`.
  - **Edit:** `CookieRepository.php` — extract `CookieEntityMapper`,
    `CookieOptimisticLocker`, `CookieEventDrainer` (split 586 → ~250 +
    3× ~100); single-statement `delete()`; `restore()` bumps version +
    checks `affectedRows`; remove `findAll`/`findPaginated` from write
    port; move `trackPopularCookie` to read repo; import `TenantContext`
    via `use` (14/F16).
  - **Edit:** `CookieQueryRepository.php` — escape `%`/`_` in LIKE;
    throw on `get() === false` (mirror write side); add `MAX_RESULTS`
    cap on `findAll`; cap `page` at `MAX_PAGE = 10000`; drop
    `BaseConnection` template params (06/F15).
  - **Edit:** `CookieRepositoryInterface.php` — `existsByName(CookieName)`;
    drop `findAll`/`findPaginated`; document `save` side effects
    (`@phpstan-impure`).
- **Owner agents:** `ddd-specialist` + `clean-code-specialist` +
  `test-specialist`.
- **Acceptance gates:**
  - `CookieRepository.php` ≤ 250 LoC (3 collaborators extracted).
  - New assertions:
    `test_repository_delete_uses_single_statement`,
    `test_repository_restore_bumps_version`,
    `test_query_repository_escapes_like_wildcards`,
    `test_get_all_cookies_rejects_unbounded_when_exceeds_max_results`,
    `test_existsByName_excludes_soft_deleted_rows`.
- **PR count estimate:** 1
- **Depends on:** **E09**
- **Risk:** MEDIUM — repo extraction touches many call sites.
- **Rollback plan:** revert the splits; the file's existing tests are
  unaffected.

### E12 — Phase 2 — Outbox table hardening: VARCHAR widening + event_uuid UNIQUE + lease columns + SKIP LOCKED
- **Phase:** 2
- **Why now:** Theme T19. Three template-multiplying defects shipping
  today: `'unsupported_schema'` truncation (CRITICAL F-O8), no
  `event_uuid` UNIQUE → duplicate delivery (CRITICAL F-I2), no lease
  semantics → multi-worker race (HIGH F-I3). Lands after E04 (which
  introduces `eventId` at the event level so the outbox can persist it).
- **Findings closed (canonical slice/F#):** `18/F-I1, 18/F-I2, 18/F-I3,
  18/F-I4, 18/F-I5, 18/F-I6, 18/F-O7, 18/F-O8, 18/F5, 18/F-A1, 18/F-A2,
  18/F-A3, 18/F-G2, 18/F-G3, 18/F-G4, 18/F6, 18/F7, 18/F-I7`
- **Files touched (estimate):** ~8 files
  - **New migration:**
    `2026-05-22-110000_HardenEventOutboxTable.php` — widen `status` to
    VARCHAR(32) with CHECK; add `event_uuid CHAR(36) NOT NULL UNIQUE`;
    add `reserved_at`, `reserved_by`, `tenant_id` columns; convert
    `payload` to JSON type; add covering index
    `(status, available_at, id)` and `(tenant_id)`; add `entity_type` /
    `entity_id` to `audit_log` (18/F-A2).
  - **Edit:** `EventOutboxWriter.php` — write `event_uuid` from
    `$event->eventId` (E04); set `tenant_id` from context.
  - **Edit:** `EventOutboxRelay.php` — use `SELECT … FOR UPDATE SKIP
    LOCKED` for claim; respect `reserved_at` lease window; INSERT IGNORE
    on retry; on MySQL only — SQLite path documented as best-effort
    serialization.
  - **Edit:** `app/Database/Migrations/...CreateCookiesTable.php` — add
    soft-delete predicate index (18/F-I6) `(deleted_at, is_active, id)`.
  - **Edit:** `app/Models/Cookie/CookieModel.php` — `description`
    length cap or document max (18/F6).
  - **New:** `app/Domain/Shared/Privacy/PiiRegistry.php` (18/F-G2 — minimal
    registry + cross-references in migrations).
  - **New:** `.claude/documentation/RETENTION.md` (18/F-I5, 18/F-G3 — doc
    + spark task hooks for `spark cleanup:audit-log`,
    `spark cleanup:outbox`).
  - **New cleanup migration / spark commands:** outbox + audit_log
    retention.
  - **Document:** drop `users.role` ENUM (18/F7) — actually delete the
    column in a follow-up since RBAC migration already exists; recorded
    in E15 PROJECTIONS.md migration notes.
- **Owner agents:** `codeigniter4-specialist` + `cqrs-specialist` +
  `test-specialist`.
- **Acceptance gates:**
  - `composer check` passes against MySQL (via E01) with new migration.
  - New assertions:
    `test_outbox_rejects_duplicate_event_uuid`,
    `test_outbox_status_accepts_full_unsupported_schema_value`,
    `test_outbox_skip_locked_lets_only_one_worker_claim`,
    `test_audit_log_indexes_aggregate_lookup_by_entity_type_and_id`.
- **PR count estimate:** 1–2 (may split: outbox migration + relay code).
- **Depends on:** **E01**, **E04** (events carry `eventId`).
- **Risk:** **HIGH** — destructive migration on the outbox table;
  rehearse on staging.
- **Rollback plan:** `migrate:rollback` reverts schema; relay code falls
  back to the legacy path documented in the slice 5 envelope versioning.

### E13 — Phase 3 — Provider DI overhaul + HTTP/auth filter + Controller refactor
- **Phase:** 3
- **Why now:** Theme T6 + T10. The magic-string DI in the provider is the
  single biggest sed-clone footgun; the missing `web_auth` filter on the
  route group is open-by-default; the missing `registerProjections()`
  hook contradicts the comment in `Services.php`. Lands before E15
  because the docs describe the new shape.
- **Findings closed (canonical slice/F#):** `08/F1, 08/F2, 08/F3, 08/F4,
  08/F5, 08/F6 (manifest-cache deferred but documented), 08/F7 (Cookie
  rename deferred), 08/F8, 08/F9, 08/F10, 08/F11, 08/F12, 08/F13, 08/F14,
  08/F15, 08/F16, 08/F17, 08/F18, 08/F19, 09/F1, 09/F2, 09/F3, 09/F4,
  09/F5, 09/F6, 09/F7, 09/F8, 09/F9, 09/F10, 09/F11, 09/F12, 09/F13,
  09/F14, 09/F15, 10/F3 (auth gate fixes feed views in E14), 14/F5,
  14/F22`
- **Files touched (estimate):** ~18 files
  - **Edit:** `app/Infrastructure/ServiceProvider/DomainServiceProviderInterface.php`
    — add `registerProjections(ProjectionRegistry $registry): void`.
  - **New:** `app/Infrastructure/ServiceProvider/RegisterProjectionsNoop.php`
    trait.
  - **Edit:** `app/Domain/Cookie/CookieServiceProvider.php` — constructor
    injection (deprecate `setRepositories`/`getRepositories`); extract
    `URI_SEGMENT`, `CONTROLLER_NAMESPACE` constants; reference
    `CookieController::class`; attach `'filter' => 'web_auth'` on route
    group; drop static `LoggerFactory::create()` in `registerEvents`.
  - **Edit:** `app/Infrastructure/ServiceProvider/ServiceProviderRegistry.php`
    — set `$providersRegistered = true` before `registerAll`;
    deterministic sort; document the (deferred) manifest cache.
  - **Edit:** `app/Config/Filters.php` — remove `cookies`/`cookies/*` from
    `web_auth` URI list.
  - **Edit:** `app/Config/Autoload.php` — delete dead `'App\\Domains'`
    mapping.
  - **Edit:** `app/Config/Events.php` — top-of-file comment disambiguating
    framework hooks vs domain events (08/F16).
  - **Edit:** `app/Config/Routes.php` — wrap auto-mount loop with
    try/catch + structured log (08/F17).
  - **Edit:** `app/Controllers/Domain/Cookie/CookieController.php` —
    `final class`; constructor injection of `$commandBus` / `$queryBus` /
    `$actorResolver` / `$logger`; generic `catch (\Throwable $e)`;
    `filter_var(..., FILTER_VALIDATE_BOOLEAN)` for `is_active`; clamp
    `page`/`per_page`; throw `PageNotFoundException` on miss in
    `show`/`edit`; mirror catch order across `store/update/delete`;
    document HTML-vs-API convention (09/F9); document `permission:` hook
    (09/F13).
  - **Edit:** `app/Controllers/BaseController.php` — remove stale CI4
    starter comments (09/F15).
  - **Edit:** Cookie feature tests (E18 closes the test side).
  - **Document:** `Config\Cookie` collision (08/F7) — LOUD top-of-file
    comment; full rename deferred to a Phase-5 follow-up if user
    approves.
- **Owner agents:** `codeigniter4-specialist` + `cqrs-specialist` +
  `clean-code-specialist`.
- **Acceptance gates:**
  - New assertions:
    `test_provider_constructor_rejects_missing_repository`,
    `test_route_group_carries_web_auth_filter`,
    `test_controller_returns_500_redirect_on_generic_throwable`,
    `test_actor_resolver_fail_closed_on_anonymous_writes`.
- **PR count estimate:** 1–2.
- **Depends on:** **E05** (typed handler interfaces simplify the
  provider's `registerCommands`).
- **Risk:** MEDIUM — auth-filter change is destructive (depends on
  `Filters.php` cleanup being correct).
- **Rollback plan:** revert provider + controller + Filters.php
  atomically.

### E14 — Phase 3 — Views: i18n adoption + view-DTO alignment + can() gates + partials wiring
- **Phase:** 3
- **Why now:** Theme T13. The views call `formattedPrice` /
  `isOutOfStock()` on a class that — after E10 — has the field name but
  not the method. Views also re-implement pagination inline (ignoring the
  partial), hard-code English in 35+ places, and render action buttons
  without `can()` gating.
- **Findings closed (canonical slice/F#):** `10/F1, 10/F2, 10/F3, 10/F4,
  10/F5, 10/F6, 10/F7, 10/F8, 10/F9, 10/F10, 10/F11, 10/F12, 10/F13,
  10/F14, 10/F15, 15/F8`
- **Files touched (estimate):** ~10 files
  - **Edit:** `app/Views/cookies/index.php`, `show.php`, `edit.php`,
    `create.php` — replace literal English with `lang('Cookies.…')`;
    wrap action buttons in `<?php if (can('cookies.…')) ?>`; wire
    `partials/_pagination.php`; extract `partials/_empty_state.php`;
    pass `$title`; switch `<?= (int) $cookie->id ?>` in URL fragments;
    resolve `<em>` ternary; choose layout (`layouts/shell.php`) and
    delete the alias (10/F14).
  - **New:** `app/Language/en/Cookies.php` with all UI strings.
  - **Edit:** `partials/_flash.php` — route messages through `lang()`
    (10/F15).
  - **Edit:** `app/Views/layout.php` — load Bootstrap Icons CSS (or
    drop the `<i>` tags consistently); add `<noscript>` warning for
    delete confirmation (10/F11).
  - **Edit:** `cookies/index.php` — comment why GET search omits CSRF
    (10/F12).
  - **Edit:** `cookies/create.php`/`edit.php` — comment Cancel `<a>` vs
    submit `<button>` convention (10/F13).
- **Owner agents:** `codeigniter4-specialist` + `clean-code-specialist`.
- **Acceptance gates:**
  - View tests assert on content, not view-paths (paired with E18).
  - New assertions: `test_index_view_renders_lang_keys_for_titles`,
    `test_action_buttons_gated_by_permission_in_view`.
- **PR count estimate:** 1
- **Depends on:** **E10**, **E13** (controller injects `$title`).
- **Risk:** MEDIUM — visual regressions possible.
- **Rollback plan:** revert views; Language file is harmless to keep.

### E15 — Phase 3 — Scaffolding skill + COMPLETE_FILE_INVENTORY + CLAUDE.md + PROJECTIONS.md + docs:cookie-sync CI guard
- **Phase:** 3
- **Why now:** Theme T5 + T15. Must land **last** in Phase 3 so it
  documents the post-remediation Cookie, not an in-flight snapshot.
- **Findings closed (canonical slice/F#):** `08/F4 (interface side), 11/F7,
  15/F1, 15/F2, 15/F3, 15/F5, 15/F6, 15/F7, 15/F11, 15/F12, 15/F13, 15/F15,
  16/F2, 16/F3, 16/F7, 16/F10, 16/F11 (seeder doc fixed by E09),
  16/F12 (projection-example doc), 16/F13, 16/F14, 18/F-G4 (encryption
  doc)`
- **Files touched (estimate):** ~10 files
  - **Edit:** `.claude/skills/domain-scaffolding/SKILL.md` — regenerate
    from current Cookie via `find app/Domain/Cookie -type f`. Add Restore
    command, StockChanged event, ReadModels (post-consolidation shape),
    Services (MoneyFormatter is Shared), ErrorCodes, Accessors trait
    note, QueryRepository + port, logging traits (Shared post-E13),
    projection-as-`.example`, tenant/outbox optional deps. Switch step
    8/9 to `#[AutoBind]` (no `Services.php` edit). Add Restore +
    lifecycle event mutators (E07). Add singular/plural convention table
    (15/F7, 15/F12).
  - **Edit:** `.claude/documentation/COMPLETE_FILE_INVENTORY.md` — match
    current Cookie file tree; fix repository path; bump file count
    (~67); add the missing 4th command, 2 events, ReadModels, Services,
    ErrorCodes, Accessors, QueryRepo, traits, projection-example.
  - **Edit:** `.claude/documentation/ADDING_DOMAINS.md` — point at the
    new `/add-domain` flow.
  - **Edit:** `.claude/CLAUDE.md` — fix the "every pattern is fully
    exemplified" claim (15/F13); add Cookie-ready-as-template note;
    cross-link the post-E18 checklist.
  - **New:** `.claude/documentation/PROJECTIONS.md` — explain when to add
    a projection; how to enable the `.example`; how to register via
    `registerProjections()` (E13).
  - **New:** `.claude/documentation/RETENTION.md` (created in E12 — link
    from here).
  - **New:** `.claude/documentation/PRODUCTION_DEPLOY.md` — encryption-
    at-rest notes (18/F-G4), SSL config, MySQL operator hand-off.
  - **Refresh or delete:**
    `.claude/documentation/SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md`
    (16/F7).
  - **New CI guard:** `composer docs:cookie-sync` script that fails when
    `app/Domain/Cookie/` changes without
    `.claude/skills/domain-scaffolding/SKILL.md` also changing in the
    same PR (15/F1 forcing function).
  - **Edit:** various Cookie source docblocks (T15-level "pattern vs
    example" annotation per slice 16 F10 — the entity, VOs, repository,
    events, controller).
- **Owner agents:** `claude-code-specialist` + `cqrs-specialist`.
- **Acceptance gates:**
  - Manual: run `/add-domain Foo`; resulting domain passes a fresh
    `composer check` with no edits.
  - The "Cookie-ready-as-template checklist" below is fully ticked.
- **PR count estimate:** 1–2.
- **Depends on:** **E04–E14** (docs describe the post-remediation Cookie).
- **Risk:** LOW — pure documentation, but the CI guard is new behaviour.
- **Rollback plan:** revert; docs alone do not affect runtime.

### E16 — Phase 4 — PHP 8.4 bump: composer.json + phpVersion + asymmetric visibility + Randomizer + #[\Deprecated]
- **Phase:** 4
- **Why now:** Theme T17. Once the codebase is stable on 8.3 and the
  phpVersion pin (E02) catches accidental 8.4 syntax, deliberately bump
  to 8.4 and adopt the features that resolve open findings.
- **Findings closed (canonical slice/F#):** `17/G1, 17/G2, 17/G3, 17/G4,
  17/G5, 17/G6, 17/G7, 17/G8, 17/G9 (PHP 8.1 enums; mostly closed in E08
  for query-logging-level; here finishes StockChangeReason), 17/G10,
  17/G11, 17/G12 (confirmation only)`
- **Files touched (estimate):** ~14 files
  - `composer.json` → `"php": "^8.4"`.
  - `phpstan.neon` → `parameters.phpVersion: 80400`.
  - `phpcs.xml` → `<config name="php_version" value="80400"/>`.
  - Slevomat ruleset → ensure property-hook / asymmetric-visibility
    sniffs.
  - `app/Domain/Cookie/Entities/Cookie.php` — `public private(set) ?int
    $id`, `public private(set) int $version`; drop `getId()`/`getVersion()`
    callers; optional property hook for `assertNotDeleted()` (17/G3).
  - `app/Domain/Shared/Logging/LogSampler.php` (created in E05) — replace
    `random_int` with `Random\Randomizer::getFloat()`.
  - `app/Domain/Cookie/ValueObjects/CookiePrice.php` — `#[\Deprecated]`
    on any retained legacy methods (most deleted in E09; this catches
    leftovers).
  - `app/Domain/Cookie/Repositories/CookieRepository.php` — `array_any`
    in `isDuplicateKey`; `new ClassName()->method()` deref in
    `EventOutboxRelay`.
  - `app/Infrastructure/Bus/Middleware/AuditMiddleware.php` —
    `#[\SensitiveParameter]` on `digestOf($command)`.
  - `app/Domain/Cookie/ValueObjects/CookieName.php` — `mb_trim()` for
    Unicode whitespace.
  - `app/Domain/Cookie/Repositories/CookieQueryRepository.php` — lazy
    objects for DTO hydration (17/G7).
  - New StockChangeReason enum (17/G9) and use in
    `Cookie::changeStock()`.
- **Owner agents:** `php-specialist` + `phpstan-specialist`.
- **Acceptance gates:**
  - `composer check` passes against MySQL + SQLite under PHP 8.4.
  - New assertions: `test_cookie_id_is_private_set_php84`,
    `test_log_sampler_uses_randomizer`,
    `test_stock_change_reason_is_enum`.
- **PR count estimate:** 1
- **Depends on:** **E02** (phpVersion pin already in place), **E05**
  (LogSampler), **E07** (entity lifecycle), **E09** (CookiePrice
  cleanup).
- **Risk:** MEDIUM — runtime engine bump; requires CI image refresh.
- **Rollback plan:** revert composer.json; CI defaults back to 8.3.

### E17 — Phase 5 — PHP 8.3 idiom polish: #[\Override], Stringable, final on Controller+Model, hrtime standardisation, typed-const fix
- **Phase:** 5
- **Why now:** Theme T16 — the tiny diffs that lift the template from
  "good 8.3" to "exemplary 8.3". Standalone from E16 because they don't
  require the 8.4 bump.
- **Findings closed (canonical slice/F#):** `02/F8 (CookieStock public
  prop style), 06/F11, 14/F4 (ErrorCodes → enum if not done in E08),
  14/F14, 14/F21, 17/F1, 17/F3, 17/F4, 17/F5, 17/F6, 17/F7, 17/F8, 17/F9,
  17/F10, 17/P1, 17/P2, 17/P3, 17/P5`
- **Files touched (estimate):** ~25 files
  - Add `#[\Override]` to ~25 sites across handlers, repository, query
    repository, dispatcher, middleware (17/F3).
  - `implements \Stringable` on `CookieName`, `CookiePrice` (17/F4).
  - `final class CookieController extends BaseController` (17/F5 — also
    09/F5).
  - `final class CookieModel extends Model` (17/F5 — also 06/F11).
  - `CookieName::MIN_LENGTH` / `MAX_LENGTH` typed const fix (17/F1).
  - Standardise `hrtime(true)` for duration across handlers + middleware
    (17/P3, 14/F21, 03/F11).
  - Replace `array_map(static fn ...)` with first-class callable across
    `CookieView::summarise`, `CookieRepository::executeFindAll`,
    `executeFindPaginated`, `CookieQueryRepository::findAll`/`findPaginated`
    (17/F8, 17/P1).
  - `EventOutboxRelay::describeListener()` — leave for now but add TODO
    comment (17/F10) referenced in E18.
  - `PriceFormatter` → `final readonly class` (14/F14) — superseded by
    move to Shared/MoneyFormatter in E10; if it survives, mark
    accordingly.
  - Drop `LOWER()` from `CookieModel::existsByName` (06/F6, 11/F13) —
    redundant after collation pin; closes 17/P2.
  - `json_validate` short-circuit in `EventOutboxRelay::decodeEnvelope`
    (17/F9).
  - `WeakMap<string, \ReflectionClass>` cache in
    `EventOutboxRelay::rehydrate` (17/P5).
- **Owner agents:** `php-specialist` + `slevomat-specialist`.
- **Acceptance gates:**
  - PHPCS Slevomat `MissingOverride` rule wired (if available in pinned
    Slevomat version) and passing zero violations.
  - New assertions:
    `test_cookie_name_implements_stringable`,
    `test_cookie_controller_is_final`,
    `test_cookie_model_is_final`,
    `test_typed_class_constants_present`.
- **PR count estimate:** 1
- **Depends on:** **E02** (phpVersion pin); LOOSE dep on E13 (controller
  not yet final until E13 lands).
- **Risk:** LOW — pure additive idiom polish.
- **Rollback plan:** revert per change; nothing is structurally
  load-bearing.

### E18 — Phase 5 — Coverage close: CookieStock + PriceFormatter + ErrorCodes + CookieAccessors + CookieFactory + MySQL-conditional integration tests + long-tail polish
- **Phase:** 5
- **Why now:** Theme T14 + long-tail mop-up of every remaining LOW / INFO
  finding from slices 06 / 09 / 10 / 11 / 12 / 13 / 15 / 16 / 18 not
  closed by earlier epics. Final coverage close + the missing tests the
  audit enumerated.
- **Findings closed (canonical slice/F#):** `12/F2, 12/F4, 12/F5, 12/F7,
  12/F8, 12/F9, 12/F10, 12/F11, 12/F12, 13/F4, 13/F8, 13/F9, 13/F10,
  13/F11, 13/F12, 13/F13, 13/F14, 13/F15, 13/F16, 13/F17, 13/missing-1..14,
  14/F23, 15/F12, 16/F11, 16/F12, 18/F-A3, 18/F-G1 (deferred E09 closure
  confirmation), 18/P4 (verified clean)`
- **Files touched (estimate):** ~22 files
  - **Edit:** `phpunit.xml.dist` — drop `force="true"` (done in E01) +
    add `<group name="mysql-only">`.
  - **Edit:** `tests/Support/IntegrationTestCase.php`,
    `FeatureTestCase.php` — remove eager `$cookieRepository` property;
    move to a `CookieIntegrationTestCase` subclass (13/F8); rewrite
    `seedActiveAdminUser` with real `HashedPassword::fromPlaintext()`;
    add `loginAs(User)` + `loginAsCustomer()` helpers (13/F5).
  - **Edit:** `tests/Support/UnitTestCase.php` — `assertExceptionMessage`
    catches `\Throwable` not `\Exception` (12/F9).
  - **Edit:** 19 unit-test files — replace `LoggerFactory::create()`
    with `$this->createMock(LoggerInterface::class)` (12/F1); add
    deptrac rule forbidding `LoggerFactory` import from `tests/Unit/`.
  - **Edit:** `CookieEventHandlersTest.php` — convert 11 smoke tests to
    log-shape assertions (12/F3).
  - **Edit:** `CookieCrudTest.php` — replace `assertSee('cookies/…')`
    with content assertions (13/F4); replace `assertFlashMessage('error')`
    with specific messages (13/F10); break `test_complete_create_update_delete_journey`
    into 7 small tests (13/F11).
  - **Edit:** `CookieRepositoryTest::test_find_paginated_orders_by_created_at_desc`
    — drop `sleep(1)`; use explicit `createdAt` (13/F6).
  - **Edit:** `CookieRepositoryTest::test_save_updates_only_changed_fields`
    — rename + add `test_save_idempotent_when_payload_unchanged`
    (13/F13).
  - **Edit:** `CookieOptimisticLockingTest::test_concurrent_modification_preserves_winners_write`
    — replace bare catch with `expectException`/`expectExceptionMessage`
    (13/F14).
  - **Edit:** `CookieFactory.php` — generic defaults (`name => 'Sample
    Item'`) (13/F16, 12/F12); fix silent-drop of `version` override
    (12/F12).
  - **New tests:**
    - `tests/Unit/Domain/Cookie/ValueObjects/CookieStockTest.php`
    - `tests/Unit/Domain/Shared/Services/MoneyFormatterTest.php`
      (post-E10)
    - `tests/Unit/Domain/Cookie/Entities/CookieAccessorsTest.php`
    - `tests/Unit/Domain/Cookie/ErrorCodesTest.php`
    - `tests/Unit/Support/Factories/CookieFactoryTest.php`
  - **New MySQL-conditional integration tests** (`markTestSkipped` if
    not on MySQL lane):
    - `test_save_idempotent_repeat_does_not_throw_concurrent_modification`
    - `test_composite_unique_rejects_active_duplicates_after_E09`
    - `test_restore_conflict_rejects_when_same_name_exists`
    - `test_tenant_write_isolation`
    - `test_pagination_beyond_last_page_returns_empty_data`
    - `test_per_page_zero_clamps_to_minimum`
    - `test_csrf_rejected_in_production_mode`
    - `test_anonymous_visitor_redirects_to_login`
    - `test_outbox_skip_locked_under_real_concurrency` (pcntl_fork or
      MySQL-only fixture).
  - **Edit:** test naming consistency check (CI rule for `test_…`
    snake_case) (12/F11 — preserve convention).
  - **Edit:** `CookieEventHandlersTest::test_…` add `Restored` /
    `StockChanged` payload-immutability tests (slice 12 missing-9).
  - **Edit:** `CookieServiceProvider::registerCommands` happy-path test
    (slice 12 missing-10).
  - **Edit:** docblock corrections per slice 16 F13 (controller actor),
    F14 (`@throws \Throwable` where appropriate).
  - **Edit:** `EventOutboxRelay::describeListener()` follow-up (17/F10
    cleanup deferred from E17).
- **Owner agents:** `test-specialist` + `codeigniter4-specialist` +
  `phpstan-specialist` + `claude-code-specialist`.
- **Acceptance gates:**
  - `composer test` green on SQLite and MySQL lanes; coverage ≥ 90 %
    on both.
  - Deptrac rule: `tests/Unit/` cannot import
    `App\Infrastructure\Logging\LoggerFactory`.
  - No `sleep(*)` anywhere in `tests/`.
  - The "Cookie-ready-as-template checklist" below has every box ticked.
- **PR count estimate:** 1–2.
- **Depends on:** all earlier epics; this is the final close.
- **Risk:** MEDIUM — large test diff; flaky-test risk on first runs.
- **Rollback plan:** revert per file; production code is unaffected.

---

## Dependency graph

```
                       ┌── E01 (MySQL CI lane) ──┐
                       │                          │
              Phase 0  ├── E02 (PHPStan pin + docblocks-audit fix)
                       │                          │
                       └── E03 (sql_mode + isolation + charset) ─┐
                                                                 │
                       ┌── E04 (AbstractDomainEvent) ────────────┤
              Phase 1  ├── E05 (Abstract handlers + bus)         │
                       └── E06 (Aggregate hydrator key)          │
                                                                 │
                       ┌── E07 (entity lifecycle) ───────────────┤
              Phase 2  ├── E08 (handler migration) ──────────────┤
                       ├── E09 (money + schema, DESTRUCTIVE)─────┤
                       ├── E10 (read-side DTO consolidation)    │
                       ├── E11 (repository hygiene)               │
                       └── E12 (outbox hardening, DESTRUCTIVE) ──┤
                                                                 │
                       ┌── E13 (provider DI + auth + controller)│
              Phase 3  ├── E14 (views + i18n + can())            │
                       └── E15 (docs catch-up, lands LAST)       │
                                                                 │
              Phase 4   ── E16 (PHP 8.4 bump + features)         │
                                                                 │
              Phase 5  ┌── E17 (PHP 8.3 idiom polish)             │
                       └── E18 (coverage close + long-tail)      │
```

**Critical path:** E01 → E02 → E03 → E04 → E05 → E09 → E11 → E10 → E13 →
E15.

**Parallelisable on two contributors:**
- Week 1: E01 (A), E02 (B), E03 (A).
- Week 2: E04 (A), E05 (B), E06 (B).
- Week 3: E07 (A), E08 (A), E12 (B).
- Week 4: E09 (B), E11 (A), E10 (A).
- Week 5: E13 (B), E14 (A), E15 (B).
- Bonus week 6 (or schedule separately): E16, E17, E18 in parallel.

---

## GitHub epic creation script

Run these `gh` commands to create all 18 epics with labels. Edit titles
as preferred; bodies link back to the plan so reviewers can read the
rationale without context-switching.

```bash
# Run from repo root. Requires `gh` authenticated.
PLAN=".audit/round3/REMEDIATION-PLAN.md"
REPORT=".audit/round3/CONSOLIDATED-REPORT.md"

# Labels
gh label create audit-round3 --color FBCA04 --description "Round 3 Cookie audit remediation" 2>/dev/null || true
gh label create epic --color 5319E7 --description "Multi-PR epic" 2>/dev/null || true
gh label create cookie-template --color 1D76DB --description "Lifts Cookie toward template-ready" 2>/dev/null || true
gh label create phase-0 --color B60205 --description "Phase 0 unblocker" 2>/dev/null || true
gh label create phase-1 --color FBCA04 --description "Phase 1 foundation" 2>/dev/null || true
gh label create phase-2 --color 0E8A16 --description "Phase 2 structural" 2>/dev/null || true
gh label create phase-3 --color 5319E7 --description "Phase 3 polish + docs" 2>/dev/null || true
gh label create phase-4 --color 6F42C1 --description "Phase 4 PHP 8.4 bump" 2>/dev/null || true
gh label create phase-5 --color BFD4F2 --description "Phase 5 coverage close" 2>/dev/null || true
gh label create destructive --color B60205 --description "Schema or breaking change" 2>/dev/null || true

# E01
gh issue create --label audit-round3,epic,cookie-template,phase-0 \
  --title "E01: MySQL CI lane + phpunit.xml.dist (drop force=SQLite)" \
  --body "$(cat <<EOF
Theme T11. Closes: 13/F1, 13/F2, 13/F17, 18/F-T1, 18/F-T2.

Plan: ${PLAN}#e01--phase-0--mysql-ci-lane--phpunitxmldist--forcesqlite-removal
Report: ${REPORT}#t11--test-infrastructure-sqlite-locked-real-fs-unit-tests-content-blind-features

Acceptance: composer test green on SQLite and MySQL lanes; CookieRepositoryTest is purely real-DB; phpunit.xml.dist no longer carries force="true".
EOF
)"

# E02
gh issue create --label audit-round3,epic,cookie-template,phase-0 \
  --title "E02: PHPStan/PHPCS phpVersion pin + docblocks:audit fix + CI runs composer ci" \
  --body "$(cat <<EOF
Theme T15. Closes: 16/F1, 16/F4, 16/F5, 16/F6, 16/F8, 16/F9, 16/F10, 16/F12, 16/F13, 16/F14, 16/F15.

Plan: ${PLAN}#e02--phase-0--phpstanphpcs-phpversion-pin--docblocksaudit-fix--ci-runs-composer-ci
Report: ${REPORT}#t15--new-documentation-gate-silently-no-op-placeholder-docblocks-shipped

Acceptance: composer ci runs in CI on every PR; bin/docblocks-audit exits 1 on placeholder docblocks; zero placeholder docblocks remain in Cookie; phpstan.neon pins phpVersion 80300.
EOF
)"

# E03
gh issue create --label audit-round3,epic,cookie-template,phase-0 \
  --title "E03: MySQL connection envelope: sql_mode + isolation + charset + DBCollat alignment" \
  --body "$(cat <<EOF
Theme T18. Closes: 18/F-C1, 18/F-C2, 18/F-C3, 18/F3, 18/F-T2.

Plan: ${PLAN}#e03--phase-0--mysql-connection-envelope-sessionvariables-sql_mode--isolation--charset--dbcollat-alignment
Report: ${REPORT}#t18--new-mysql-connection-envelope-unpinned

Acceptance: composer test green on MySQL lane with new envelope; @@sql_mode, @@transaction_isolation, @@time_zone all pinned at connect.
EOF
)"

# E04
gh issue create --label audit-round3,epic,cookie-template,phase-1 \
  --title "E04: AbstractDomainEvent + eventId/occurredAt/actorId envelope" \
  --body "$(cat <<EOF
Theme T1. Closes: 01/F12, 03/F1 (event side), 03/F15, 05/F1, 05/F2, 05/F3, 05/F4, 05/F5, 05/F6, 05/F9, 12/F6, 14/F18, 15/F11.

Plan: ${PLAN}#e04--phase-1--abstractdomainevent--eventidoccurredatactorid-envelope
Report: ${REPORT}#t1--no-abstract-base-for-domain-events-payload-asymmetry

Acceptance: every Cookie event extends AbstractDomainEvent; eventId is UUIDv7; occurredAt is immutable UTC; CookieStockChangedEvent.cookieId non-nullable.
EOF
)"

# E05
gh issue create --label audit-round3,epic,cookie-template,phase-1 \
  --title "E05: AbstractCommandHandler + AbstractQueryHandler + bus-enforced interfaces" \
  --body "$(cat <<EOF
Theme T2. Closes: 03/F3, 03/F4, 03/F5, 03/F6, 03/F11, 03/F12, 03/F14, 03/F16, 04/F1, 04/F3, 04/F7, 04/F10, 04/F12, 14/F1, 14/F2, 14/F3, 14/F20, 14/F21, 17/F2, 17/P3.

Plan: ${PLAN}#e05--phase-1--abstractcommandhandler--abstractqueryhandler--bus-enforced-interfaces

Acceptance: PHPStan L8 narrows handler types end-to-end; slow queries logged at warning; sampling uses random_int; LogSampler is uniform.
EOF
)"

# E06
gh issue create --label audit-round3,epic,cookie-template,phase-1 \
  --title "E06: AggregateRootInterface + AggregateHydrator key + reconstitute version guard" \
  --body "$(cat <<EOF
Theme T7 (foundation). Closes: 01/F4, 01/F5, 01/F10.

Plan: ${PLAN}#e06--phase-1--aggregaterootinterface--aggregatehydrator-key--reconstitute-version-guard

Acceptance: reconstitute rejects version=0; bumpVersion/assignId require hydrator key; Cookie implements AggregateRootInterface.
EOF
)"

# E07
gh issue create --label audit-round3,epic,cookie-template,phase-2 \
  --title "E07: Cookie entity lifecycle: softDelete/restore/activate/deactivate raise events" \
  --body "$(cat <<EOF
Theme T7. Closes: 01/F1, 01/F2, 01/F3, 01/F6, 01/F7, 01/F9, 01/F11, 03/F1 (entity side), 14/F7, 14/F13, 14/F17, 15/F14.

Plan: ${PLAN}#e07--phase-2--cookie-entity-lifecycle-softdeleterestoreactivatedeactivate-raise-events
Depends on: E04, E06.

Acceptance: softDelete/restore raise events; activate/deactivate raise events; assertPersisted uses COOKIE_STATE_NOT_PERSISTED; getStock returns CookieStock.
EOF
)"

# E08
gh issue create --label audit-round3,epic,cookie-template,phase-2 \
  --title "E08: Cookie handler migration to abstract bases + RestoreCookieHandler parity" \
  --body "$(cat <<EOF
Theme T2 consumption + 03/F2 wildcard. Closes: 03/F1 (handler side), 03/F2, 03/F7, 03/F8, 03/F9, 03/F10, 03/F12, 03/F13, 03/F14, 03/F15, 04/F1 (consumption), 04/F2, 04/F4, 04/F5, 04/F6, 04/F8, 04/F9, 04/F11, 14/F12, 14/F19.

Plan: ${PLAN}#e08--phase-2--cookie-handler-migration-to-abstract-bases--restorecookiehandler-parity
Depends on: E05.

Acceptance: every handle() <= 20 lines; all four command handler failure-log shapes identical; RestoreCookieCommand renames cookieId->id; GetAllCookies caps at MAX_RESULTS.
EOF
)"

# E09
gh issue create --label audit-round3,epic,cookie-template,phase-2,destructive \
  --title "E09: Multi-currency schema + VOs + DECIMAL->price_minor migration + seeder rewrite" \
  --body "$(cat <<EOF
Theme T3. Closes: 02/F1, 02/F2, 02/F3, 02/F4, 02/F5, 02/F6, 02/F7, 02/F8, 02/F9, 02/F10, 05/F9, 11/F1, 11/F2, 11/F4, 11/F5, 11/F6, 11/F9, 11/F10, 11/F11, 11/F12, 11/F13, 14/F8, 14/F9, 14/F14, 15/F2, 15/F4, 18/F2, 18/F3, 18/F4, 18/F-S1, 18/F-S2, 18/F-S3, 18/F-FK1, 18/F-G1, 18/F-FK2, 18/F-FK3, 18/F-M1, 18/F-M2, 18/F8.

Plan: ${PLAN}#e09--phase-2--multi-currency-cookieprice-bounds--decimalprice_minor-migration--seeder-rewrite
Depends on: E01 (MySQL lane), E03 (sql_mode).

DESTRUCTIVE: rehearse on staging. Acceptance: CookiePrice requires explicit Currency; bounds respect currency.decimals (JPY/BHD); composite UNIQUE rejects active duplicates; purge() implemented.
EOF
)"

# E10
gh issue create --label audit-round3,epic,cookie-template,phase-2 \
  --title "E10: Read-side consolidation: CookieDTO + JsonSerializable + ReadDTOInterface + MoneyFormatter" \
  --body "$(cat <<EOF
Theme T4. Closes: 04/F9, 07/F1, 07/F2, 07/F3, 07/F4, 07/F5, 07/F6, 07/F7, 07/F8, 07/F9, 07/F10, 07/F11, 07/F12, 07/F13, 07/F14, 14/F8 (partial), 14/F10, 14/F11, 14/F15, 15/F10.

Plan: ${PLAN}#e10--phase-2--read-side-consolidation-cookiedto--jsonserializable--readdtointerface--moneyformatter
Depends on: E09, E11.

Acceptance: CookieView deleted; CookieDTO implements JsonSerializable snake_case; fromRow() exists; ReadDTOInterface exists; MoneyFormatter lives in Domain/Shared.
EOF
)"

# E11
gh issue create --label audit-round3,epic,cookie-template,phase-2 \
  --title "E11: Repository hygiene: trusted reconstitution, LIKE escape, single-statement delete" \
  --body "$(cat <<EOF
Theme T9 + T8. Closes: 06/F1, 06/F4, 06/F5, 06/F6, 06/F7, 06/F8, 06/F9, 06/F10, 06/F11, 06/F12, 06/F13, 06/F14, 06/F15, 06/F16, 06/F17, 11/F3, 11/F13, 14/F6, 14/F16, 14/F19, 15/F9, 15/F15.

Plan: ${PLAN}#e11--phase-2--repository-hygiene-trusted-reconstitution-like-escape-single-statement-delete
Depends on: E09.

Acceptance: CookieRepository <= 250 LoC; delete() single-statement; restore() bumps version; LIKE wildcards escaped; existsByName drops withDeleted.
EOF
)"

# E12
gh issue create --label audit-round3,epic,cookie-template,phase-2,destructive \
  --title "E12: Outbox table hardening: VARCHAR widen + event_uuid UNIQUE + lease cols + SKIP LOCKED" \
  --body "$(cat <<EOF
Theme T19. Closes: 18/F-I1, 18/F-I2, 18/F-I3, 18/F-I4, 18/F-I5, 18/F-I6, 18/F-I7, 18/F-O7, 18/F-O8, 18/F5, 18/F-A1, 18/F-A2, 18/F-A3, 18/F-G2, 18/F-G3, 18/F-G4, 18/F6, 18/F7.

Plan: ${PLAN}#e12--phase-2--outbox-table-hardening-varchar-widening--event_uuid-unique--lease-columns--skip-locked
Depends on: E01, E04.

DESTRUCTIVE: outbox-table migration; rehearse on staging. Acceptance: status accepts 'unsupported_schema'; duplicate event_uuid rejected; SKIP LOCKED ensures one claim per row.
EOF
)"

# E13
gh issue create --label audit-round3,epic,cookie-template,phase-3 \
  --title "E13: Provider DI overhaul + HTTP/auth filter + Controller refactor" \
  --body "$(cat <<EOF
Theme T6 + T10. Closes: 08/F1-F19 (most), 09/F1-F15 (most), 10/F3, 14/F5, 14/F22.

Plan: ${PLAN}#e13--phase-3--provider-di-overhaul--httpauth-filter--controller-refactor
Depends on: E05.

Acceptance: provider uses constructor injection; route group carries web_auth filter; controller is final with constructor-injected buses + generic Throwable catch.
EOF
)"

# E14
gh issue create --label audit-round3,epic,cookie-template,phase-3 \
  --title "E14: Views: i18n + view-DTO alignment + can() gates + partials wiring" \
  --body "$(cat <<EOF
Theme T13. Closes: 10/F1, 10/F2, 10/F3, 10/F4, 10/F5, 10/F6, 10/F7, 10/F8, 10/F9, 10/F10, 10/F11, 10/F12, 10/F13, 10/F14, 10/F15, 15/F8.

Plan: ${PLAN}#e14--phase-3--views-i18n-adoption--view-dto-alignment--can-gates--partials-wiring
Depends on: E10, E13.

Acceptance: views use lang() + can() + partials/_pagination; CookieView accessors no longer referenced.
EOF
)"

# E15
gh issue create --label audit-round3,epic,cookie-template,phase-3 \
  --title "E15: Scaffolding + COMPLETE_FILE_INVENTORY + CLAUDE.md + PROJECTIONS.md + docs:cookie-sync CI guard" \
  --body "$(cat <<EOF
Theme T5 + T15 (docs side). Closes: 08/F4 (interface side), 11/F7, 15/F1, 15/F2, 15/F3, 15/F5, 15/F6, 15/F7, 15/F11, 15/F12, 15/F13, 15/F15, 16/F2, 16/F3, 16/F7, 16/F10, 16/F11, 16/F12, 16/F13, 16/F14, 18/F-G4.

Plan: ${PLAN}#e15--phase-3--scaffolding-skill--complete_file_inventory--claudemd--projectionsmd--docscookie-sync-ci-guard
Depends on: E04-E14.

Acceptance: /add-domain Foo produces a domain that passes composer check unedited; Cookie-ready-as-template checklist fully ticked; docs:cookie-sync CI guard added.
EOF
)"

# E16
gh issue create --label audit-round3,epic,cookie-template,phase-4 \
  --title "E16: PHP 8.4 bump + asymmetric visibility + Randomizer + #[\\Deprecated]" \
  --body "$(cat <<EOF
Theme T17. Closes: 17/G1, 17/G2, 17/G3, 17/G4, 17/G5, 17/G6, 17/G7, 17/G8, 17/G9, 17/G10, 17/G11, 17/G12.

Plan: ${PLAN}#e16--phase-4--php-84-bump-composerjson--phpversion--asymmetric-visibility--randomizer--deprecated
Depends on: E02 (phpVersion pin), E05 (LogSampler), E07 (entity lifecycle), E09 (CookiePrice cleanup).

Acceptance: composer check passes on PHP 8.4; Cookie::id is private(set); LogSampler uses Randomizer; StockChangeReason is enum.
EOF
)"

# E17
gh issue create --label audit-round3,epic,cookie-template,phase-5 \
  --title "E17: PHP 8.3 idiom polish: #[\\Override] + Stringable + final on Controller+Model" \
  --body "$(cat <<EOF
Theme T16. Closes: 02/F8, 06/F11, 14/F4, 14/F14, 14/F21, 17/F1, 17/F3-F10, 17/P1-P5.

Plan: ${PLAN}#e17--phase-5--php-83-idiom-polish-override-stringable-final-on-controllermodel-hrtime-standardisation-typed-const-fix
Depends on: E02, E13.

Acceptance: #[\Override] on ~25 sites; VOs implement Stringable; CookieController/CookieModel are final; CookieName typed-consts present.
EOF
)"

# E18
gh issue create --label audit-round3,epic,cookie-template,phase-5 \
  --title "E18: Coverage close + MySQL-conditional integration tests + long-tail polish" \
  --body "$(cat <<EOF
Theme T14 + long-tail. Closes: all remaining 12/F# + 13/F#; 13/missing-1..14; 14/F23, 15/F12, 16/F11, 16/F12, 18/F-A3, 18/F-G1 confirmation, 18/P4.

Plan: ${PLAN}#e18--phase-5--coverage-close-cookiestock--priceformatter--errorcodes--cookieaccessors--cookiefactory--mysql-conditional-integration-tests--long-tail-polish
Depends on: all earlier epics.

Acceptance: composer test green on SQLite and MySQL with coverage >= 90% on both; deptrac forbids LoggerFactory in tests/Unit/; no sleep() in tests; Cookie-ready-as-template checklist fully ticked.
EOF
)"
```

---

## Finding-to-Epic Matrix

Every raw finding from every slice. Columns: Slice / F# / Severity / One-line
title / Epic / Notes (if dedup / theme tag).

| Slice | F# | Sev | Title | Epic |
|-------|----|-----|-------|------|
| 01 | F1 | HIGH | `Cookie::update()` silently drops event when pre-persist | E07 |
| 01 | F2 | HIGH | `activate/deactivate` raise no event | E07 |
| 01 | F3 | HIGH | softDelete/restore not entity methods | E07 |
| 01 | F4 | HIGH | `reconstitute()` default `version=0` wrong direction | E06 |
| 01 | F5 | MED | `@internal public` not enforced (PHP 8.4 G1 supersedes) | E06 (key); E16 (private(set)) |
| 01 | F6 | MED | `assertPersisted` uses wrong error code | E07 |
| 01 | F7 | MED | `changeStock` casts `(int) $this->id` | E07 |
| 01 | F8 | MED | `CookieAccessors` `@property` brittle dep | E18 (note in trait) |
| 01 | F9 | MED | `decreaseStock` stringly-typed reason | E07 (note); E16 (StockChangeReason enum) |
| 01 | F10 | LOW | Missing `implements AggregateRootInterface` | E06 |
| 01 | F11 | LOW | `snapshot()` heterogeneous types | E07 |
| 01 | F12 | INFO | Class docblock encodes split-dispatch as canon | E04 |
| 02 | F1 | CRIT | USD-cents bounds on every currency | E09 |
| 02 | F2 | HIGH | `defaultCurrency()` env-read silent USD | E09 |
| 02 | F3 | HIGH | Name equality split equals/equalsIgnoreCase | E09 |
| 02 | F4 | HIGH | CookieStock no maximum; overflow | E09 |
| 02 | F5 | MED | Asymmetric error codes across CookiePrice exceptions | E09 |
| 02 | F6 | MED | No `JsonSerializable` on Cookie VOs | E09 |
| 02 | F7 | MED | `equalsIgnoreCase(string)` bypasses validation | E09 |
| 02 | F8 | LOW | `CookieStock::$value` public; siblings private | E17 |
| 02 | F9 | LOW | `CookiePrice::format()` deprecation couples to Service | E09 |
| 02 | F10 | INFO | `CookieStock::fromInt` naming after primitive | E09 |
| 03 | F1 | CRIT | Three competing event-dispatch patterns | E04 (event); E07 (entity); E08 (handler) |
| 03 | F2 | CRIT | `RestoreCookieHandler` violates every convention | E08 |
| 03 | F3 | HIGH | `handle()` methods 70-94 lines | E05; consumed by E08 |
| 03 | F4 | HIGH | `determineErrorCode()` str_contains | E05 |
| 03 | F5 | HIGH | `CommandBus` duck-typed | E05 |
| 03 | F6 | HIGH | `UpdateCookieHandler` failure-log shape diverges | E05; E08 |
| 03 | F7 | HIGH | Command shape drift ($cookieId vs $id) | E08 |
| 03 | F8 | HIGH | TransactionMiddleware silent on TOCTOU | E08 (doc); E09 (DB-level UNIQUE) |
| 03 | F9 | MED | `expectedVersion` opt-in | E08 (doc) |
| 03 | F10 | MED | RestoreCookieHandler `COOKIE_NOT_FOUND` for not-deleted | E08 |
| 03 | F11 | MED | `DeleteCookieHandler` uses `hrtime`, others `microtime` | E05; E17 |
| 03 | F12 | MED | Hard-coded `'Cookie'` in log payloads | E05; E08 |
| 03 | F13 | MED | DeleteCookieHandler builds manual snapshot | E08 |
| 03 | F14 | LOW | Per-handler log channel docblock unused | E05; E08 |
| 03 | F15 | LOW | RestoreCookieEvent string `restoredAt` | E04 |
| 03 | F16 | INFO | `CommandBus::dispatch` dead `method_exists` | E05 |
| 04 | F1 | HIGH | Query-handler logging boilerplate duplicated 3x | E05; E08 |
| 04 | F2 | HIGH | `GetAllCookiesQuery` unbounded | E08 |
| 04 | F3 | HIGH | No `QueryHandlerInterface<TQuery,TResult>` | E05 |
| 04 | F4 | MED | Search term not length-capped / LIKE-escaped | E08; E11 |
| 04 | F5 | MED | No sort/order input on paginated query | E08 (doc); E16 (enum) |
| 04 | F6 | MED | Page floor but no ceiling | E08 |
| 04 | F7 | MED | Slow queries logged at info | E05 |
| 04 | F8 | MED | Search analytics override bypasses log-level config | E08 |
| 04 | F9 | MED | GetCookieById null-on-miss no contract | E08; E10 |
| 04 | F10 | LOW | Hard-coded `'Cookie'` / query-class strings | E05; E08 |
| 04 | F11 | LOW | No caching seam | E08 (hook only) |
| 04 | F12 | LOW | `mt_rand()` sampling vs `random_int` | E05; E16 (Randomizer) |
| 04 | F13 | INFO | `GetAllCookiesQuery` misleading docblock | E08 |
| 05 | F1 | HIGH | Event payloads asymmetric across 5 events | E04 |
| 05 | F2 | HIGH | `CookieStockChangedEvent::$cookieId` nullable | E04 |
| 05 | F3 | MED | `CookieRestoredEvent::$restoredAt` raw string | E04 |
| 05 | F4 | MED | Unbounded `scalar|null` snapshots — PII | E04 (typed envelope); E12 (PiiRegistry) |
| 05 | F5 | MED | No `eventId`; no idempotency guard | E04; E12 |
| 05 | F6 | MED | Placeholder `__construct.` docblocks | E02 |
| 05 | F7 | LOW | `EventDispatcher` not `final` (PHPUnit) | E18 (doc only) |
| 05 | F8 | LOW | `dispatch()` short-circuits silently | E18 |
| 05 | F9 | LOW | Event price format ambiguous | E04; E09 |
| 05 | F10 | INFO | Projection `.php.example` deprecation header exemplary | E15 (kept) |
| 06 | F1 | HIGH | `existsByName` `withDeleted` contradicts schema | E09; E11 |
| 06 | F2 | HIGH | `'cookie'` metric slice key survives sed | E13 |
| 06 | F3 | HIGH | RepositoryLogging/BusinessMetricsLogging hardcode `'Cookie'` | E13 |
| 06 | F4 | HIGH | Read `LIKE` unescaped — `%`/`_` injection | E11 |
| 06 | F5 | MED | Write/read paginate disagree on default sort | E11 |
| 06 | F6 | MED | `LOWER(name)` voids index | E09; E11; E17 |
| 06 | F7 | MED | Reconstitute re-validates VOs (throws on legacy) | E11 |
| 06 | F8 | MED | `delete()` SELECT-then-UPDATE-twice | E11 |
| 06 | F9 | MED | `restore()` no version bump | E11 |
| 06 | F10 | MED | `CookieQueryRepository::findPaginated` swallows false | E11 |
| 06 | F11 | LOW | `CookieModel` not final, leaky `$db` | E09; E17 |
| 06 | F12 | LOW | write-side findById triggers `trackPopularCookie` | E11 |
| 06 | F13 | LOW | `save()` undocumented side effect | E11 |
| 06 | F14 | LOW | No hard-delete escape hatch | E09 (purge added) |
| 06 | F15 | LOW | `BaseConnection` template over-specified | E11 |
| 06 | F16 | INFO | Model `$validationRules` duplicates VO invariants | E11 (doc) |
| 06 | F17 | INFO | Write port `existsByName(string)` leaks scalar | E11 |
| 07 | F1 | CRIT | Two competing read-DTOs; CookieView dead code | E10 |
| 07 | F2 | CRIT | `PriceFormatter` bypassed by every caller | E10 |
| 07 | F3 | HIGH | `PriceFormatter` not stateless / not locale-aware | E10 |
| 07 | F4 | HIGH | `CookieDTO::isOutOfStock()` violates DTO docblock | E10 |
| 07 | F5 | HIGH | `CookieDTO::id` nullable | E10 |
| 07 | F6 | HIGH | DTO vs View represent price differently | E10 |
| 07 | F7 | HIGH | `CookieView` factories take Cookie entity | E10 |
| 07 | F8 | MED | `CookieView` can't represent soft-deleted state | E10 |
| 07 | F9 | MED | `CookieView::$extra` dead state | E10 |
| 07 | F10 | MED | DTOs/ vs ReadModels/ naming inconsistency | E10; E15 |
| 07 | F11 | MED | Asymmetric factories (fromEntity vs detail/summary) | E10 |
| 07 | F12 | LOW | `CookieView` private __construct but public props | E10 |
| 07 | F13 | LOW | No defensive copies (safe; noted) | E10 (doc) |
| 07 | F14 | LOW | Hard-coded Cookie bits in formatter — generic | E10 |
| 08 | F1 | CRIT | Provider namespace string sed-hostile | E13 |
| 08 | F2 | CRIT | `getRepository()` undefined-index-faults silently | E13 |
| 08 | F3 | CRIT | `registerEvents()` constructs own logger | E13 |
| 08 | F4 | HIGH | `DomainServiceProviderInterface` no `registerProjections()` | E13; E15 |
| 08 | F5 | HIGH | setRepositories/getRepositories defeats `#[AutoBind]` | E13 |
| 08 | F6 | HIGH | Provider discovery rescans on cold start | E13 (doc; cache deferred to E18 long-tail) |
| 08 | F7 | HIGH | `Config\Cookie` framework class collides with Cookie domain | E13 (doc); full rename deferred to follow-up issue |
| 08 | F8 | HIGH | `Services::ensureProvidersRegistered()` re-entrance | E13 |
| 08 | F9 | MED | `lcfirst(shortName)` repository key sed-fragile | E13 |
| 08 | F10 | MED | `Autoload.php` dead `'App\\Domains'` mapping | E13 |
| 08 | F11 | MED | `registerCommands` 75-line method | E13 |
| 08 | F12 | MED | Eager handler construction | E13 |
| 08 | F13 | MED | `setRepositories()` overwrites instead of merging | E13 |
| 08 | F14 | MED | `getRepository()` returns `object` | E13 |
| 08 | F15 | LOW | `'cookie.events'` channel doesn't match `deriveLogChannel` | E13 |
| 08 | F16 | LOW | `Config\Events.php` naming overload | E13 |
| 08 | F17 | LOW | Routes auto-mount loop no error handling | E13 |
| 08 | F18 | LOW | `getRepositories(): array<mixed>` | E13 |
| 08 | F19 | INFO | `RegisterRoutesNoop` trait referenced but unused | E13 |
| 09 | F1 | CRIT | Route group has no `web_auth` filter | E13 |
| 09 | F2 | HIGH | No generic `Throwable` catch in controller | E13 |
| 09 | F3 | HIGH | Service-locator per action; no constructor injection | E13 |
| 09 | F4 | HIGH | `(bool) $isActiveParam` permissive | E13 |
| 09 | F5 | MED | `CookieController` not `final` | E13; E17 |
| 09 | F6 | MED | `index` hard-codes `perPage: 20` | E13 |
| 09 | F7 | MED | `ActorResolver` returns `Actor::system()` for anonymous | E13 |
| 09 | F8 | MED | `redirect()->back()` no fallback target | E13 |
| 09 | F9 | MED | POST /delete vs DELETE convention not documented | E13 (doc) |
| 09 | F10 | LOW | Namespace stem `Domain\Cookie` vs URI stem `cookies` | E13 (doc); E15 |
| 09 | F11 | LOW | show/edit swallow not-found via redirect | E13 |
| 09 | F12 | LOW | `$price = is_string ? : ''` hides type confusion | E13 |
| 09 | F13 | LOW | No `permission:` filter wired | E13 (doc) |
| 09 | F14 | LOW | `delete()` doesn't catch `ValidationException` | E13 |
| 09 | F15 | INFO | `BaseController::initController` stale preload comments | E13 |
| 10 | F1 | HIGH | Views call `formattedPrice`/`isOutOfStock()` not on CookieView | E10; E14 |
| 10 | F2 | HIGH | Hard-coded English strings — `lang()` not used | E14 |
| 10 | F3 | HIGH | Action buttons render without `can()` gating | E14 |
| 10 | F4 | HIGH | Pagination duplicated inline, partial unused | E14 |
| 10 | F5 | MED | `show.php:36` renders HTML in ternary | E14 |
| 10 | F6 | MED | Bootstrap Icons referenced but never loaded | E14 |
| 10 | F7 | MED | Date/price formatting inconsistent across views | E14 |
| 10 | F8 | MED | Empty-state markup duplicated inline | E14 |
| 10 | F9 | LOW | No `$title` passed to layout | E14 |
| 10 | F10 | LOW | `<?= $cookie->id ?>` no `esc()` | E14 |
| 10 | F11 | LOW | Delete `data-confirm` JS degrades without JS | E14 |
| 10 | F12 | LOW | Search form omits CSRF | E14 (doc) |
| 10 | F13 | LOW | Cancel `<a>` next to submit `<button>` | E14 (doc) |
| 10 | F14 | INFO | Two layouts (`layout.php` + `layouts/shell.php`) | E14 |
| 10 | F15 | INFO | `_flash.php` keys hard-coded English | E14 |
| 11 | F1 | HIGH | Money/schema mismatch DECIMAL(10,2) vs Money | E09 |
| 11 | F2 | HIGH | Seeder bypasses VOs | E09 |
| 11 | F3 | HIGH | `existsByName` contradicts migration's UNIQUE semantics | E09; E11 |
| 11 | F4 | MED | Create-then-Drop read-model migration pair | E09 |
| 11 | F5 | MED | No explicit ENGINE/CHARSET/COLLATE on createTable | E09 |
| 11 | F6 | MED | No FKs on tenant_id/created_by/updated_by/deleted_by | E09 |
| 11 | F7 | MED | Filename convention inconsistent | E15 (doc only; deferred to follow-up rename) |
| 11 | F8 | LOW | `cookies` table name hard-coded | E15 (scaffolding note) |
| 11 | F9 | LOW | created_at/updated_at/deleted_at all nullable | E09 |
| 11 | F10 | LOW | `is_active TINYINT(1)` ambiguous | E09 |
| 11 | F11 | LOW | Composite UNIQUE NULL-vs-NULL allows duplicates | E09 |
| 11 | F12 | INFO | `version` default `0` vs `1` | E09 |
| 11 | F13 | INFO | No covering index for `LOWER(name)` | E09; E11 |
| 12 | F1 | HIGH | 19 unit tests open real LoggerFactory | E18 |
| 12 | F2 | HIGH | Missing tests CookieStock/PriceFormatter/Accessors/ErrorCodes | E18 |
| 12 | F3 | HIGH | 11 event-handler tests assertTrue(true) | E18 |
| 12 | F4 | MED | `expectException(\Exception::class)` too broad | E18 |
| 12 | F5 | MED | `CookieEventsTest` immutability tests tautological | E18 |
| 12 | F6 | MED | `CookieDeletedEvent` carries no `deletedBy`/`deletedAt` | E04; E18 |
| 12 | F7 | LOW | `CookieFactory::createDatabaseRow` dead code | E18 |
| 12 | F8 | LOW | `determine_error_code_match_arms` test asserts message, not code | E18 |
| 12 | F9 | LOW | `UnitTestCase::assertExceptionMessage` catches `\Exception` | E18 |
| 12 | F10 | LOW | Whitespace drift in `version: 1` | E18 |
| 12 | F11 | INFO | Test naming consistent (`test_…` snake_case) — keep | E18 (preserve) |
| 12 | F12 | INFO | `CookieFactory::createPersistedCookie` silently drops version override | E18 |
| 12 | missing-1..10 | — | Missing direct tests | E18 |
| 13 | F1 | CRIT | `phpunit.xml.dist:67` force-locks DB to SQLite | E01 |
| 13 | F2 | CRIT | CookieRepositoryTest mixes real-DB with createMock | E01 |
| 13 | F3 | HIGH | Composite UNIQUE never exercised (NULL-vs-NULL) | E09; E18 |
| 13 | F4 | HIGH | Feature tests `assertSee('cookies/index')` | E18 |
| 13 | F5 | HIGH | `loginAsAdmin` bypasses real auth | E18 |
| 13 | F6 | HIGH | `sleep(1)` in `test_find_paginated_orders_by_created_at_desc` | E18 |
| 13 | F7 | HIGH | Pagination edge cases not covered | E18 |
| 13 | F8 | MED | `IntegrationTestCase` eagerly constructs `CookieRepository` | E18 |
| 13 | F9 | MED | Tenant test inserts via raw `Database::connect` | E18 |
| 13 | F10 | MED | `assertFlashMessage('error')` no specific message | E18 |
| 13 | F11 | MED | 50-line monolithic "journey" test | E18 |
| 13 | F12 | LOW | `test_supports_explicit_page_parameter` asserts only assertOK | E18 |
| 13 | F13 | LOW | `test_save_updates_only_changed_fields` misnamed | E18 |
| 13 | F14 | LOW | `CookieOptimisticLockingTest` bare catch DomainException | E18 |
| 13 | F15 | LOW | CSRF silently disabled in test env | E18 |
| 13 | F16 | INFO | Factory defaults won't sed-clone | E18 |
| 13 | F17 | INFO | Class-level `#[AllowMockObjectsWithoutExpectations]` | E01 |
| 13 | missing-1..14 | — | Missing integration tests | E18 |
| 14 | F1 | HIGH | Command-handler boilerplate duplicated 4× | E05; E08 |
| 14 | F2 | HIGH | `determineErrorCode()` substring matching | E05 |
| 14 | F3 | HIGH | Query-handler logging policy copy-pasted 3× | E05 |
| 14 | F4 | HIGH | `ErrorCodes` is class of const int, not enum | E08 (enum used by base); E17 |
| 14 | F5 | HIGH | Provider resolves through string-keyed array | E13 |
| 14 | F6 | HIGH | `CookieRepository` 586 LoC | E11 |
| 14 | F7 | MED | Entity carries deprecated/legacy concerns | E07 |
| 14 | F8 | MED | `CookiePrice`/`CookieName` ship `@deprecated` methods | E09; E10 |
| 14 | F9 | MED | `CookieDTO::fromEntity` calls deprecated method | E10 |
| 14 | F10 | MED | `CookieView` `$extra = []` "currently unused" | E10 |
| 14 | F11 | MED | `CookieView::detail()` coerces null id to 0 | E10 |
| 14 | F12 | MED | Logging context keys mix snake/camel case | E05; E08 |
| 14 | F13 | MED | `getStock()` returns int unwrapping the VO | E07 |
| 14 | F14 | LOW | `PriceFormatter` `final class` not `final readonly` | E10; E17 |
| 14 | F15 | LOW | Placeholder docblocks `__construct.` | E02 |
| 14 | F16 | LOW | `TenantContext` FQN inline | E11 |
| 14 | F17 | LOW | `(int) $this->id` cast after `assertPersisted` | E07 |
| 14 | F18 | LOW | `CookieRestoredEvent::restoredAt` raw string | E04 |
| 14 | F19 | LOW | Two-layer clamping in paginated query | E08; E11 |
| 14 | F20 | LOW | `mt_rand()` sampling duplicated 3× | E05 |
| 14 | F21 | INFO | Mixed time bases microtime/hrtime | E05; E17 |
| 14 | F22 | INFO | `LoggerFactory::create('cookie.events')` static call | E13 |
| 14 | F23 | INFO | `CookieAccessors` trait `@property` duplication | E18 |
| 15 | F1 | CRIT | Scaffolding skill describes a Cookie that hasn't existed | E15 |
| 15 | F2 | CRIT | Reference projection `.example` + Create/Drop migration pair | E09 (squash); E15 (doc) |
| 15 | F3 | HIGH | "Shared" infra under Cookie namespace | E10 (MoneyFormatter); E13 (RepositoryLogging move) |
| 15 | F4 | HIGH | USD-cents bounds and currency default in price VO | E09 |
| 15 | F5 | HIGH | ErrorCodes collision contract not enforced | E08 (base handler stamps domain) |
| 15 | F6 | HIGH | File inventory + SKILL.md disagree about repo location | E15 |
| 15 | F7 | MED | Plural/pluralisation literals scattered | E15 (doc); E14 |
| 15 | F8 | MED | Hard-coded English copy with no i18n hook | E14 |
| 15 | F9 | MED | `Cookie.php` 288 lines / `CookieRepository.php` 586 lines | E07; E11 |
| 15 | F10 | MED | DTO + ReadModel parallel structures | E10 |
| 15 | F11 | MED | Restore command missing from scaffolding promise | E04; E15 |
| 15 | F12 | LOW | Singular/plural inconsistency | E15 |
| 15 | F13 | LOW | CLAUDE.md asserts "every pattern is fully exemplified" — false | E15 |
| 15 | F14 | LOW | ErrorCodes constants used inconsistently inside entity | E07 |
| 15 | F15 | INFO | `findByIdWithTrashed` on write port, no read-port equivalent | E11 (doc) |
| 16 | F1 | HIGH | 26 placeholder method docblocks | E02 |
| 16 | F2 | HIGH | `COMPLETE_FILE_INVENTORY.md` 2 phases stale | E15 |
| 16 | F3 | HIGH | `domain-scaffolding/SKILL.md` points at non-existent paths | E15 |
| 16 | F4 | MED | `CookieRepository.php` wrong `@package` tag | E02 |
| 16 | F5 | MED | `RestoreCookieHandler` class-level docblock is one-word stub | E02; E08 |
| 16 | F6 | MED | `CookieRepositoryInterface::findById` docblock no soft-delete contract | E02 |
| 16 | F7 | MED | `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` describes 23-file Cookie | E02 (delete); E15 (refresh) |
| 16 | F8 | HIGH | `composer docblocks:audit` in `composer check` but NOT in CI | E02 |
| 16 | F9 | LOW | `CookieAccessors` `@property` block can be misread | E02 (note); E18 |
| 16 | F10 | LOW | Cookie ports/DTOs prose hard-codes Cookie metaphor | E15 |
| 16 | F11 | LOW | `CookieSeeder` docblock omits required columns | E02 (doc); E09 (fix) |
| 16 | F12 | LOW | Projection `.example` docblock contradicts migration | E15 |
| 16 | F13 | INFO | Controller docblock predates actor-resolver wiring | E13 |
| 16 | F14 | INFO | `@throws` annotations inconsistent across handlers | E18 |
| 16 | F15 | INFO | `LOGGING_BEST_PRACTICES.md` and `GIT_WORKFLOW.md` cross-check note | E15 |
| 17 | F1 | MED | `CookieName::MIN/MAX_LENGTH` not typed const | E09; E17 |
| 17 | F2 | HIGH | `mt_rand()` for log sampling × 3 sites, biased | E05; E16 |
| 17 | F3 | MED | No `#[\Override]` attribute anywhere | E17 |
| 17 | F4 | MED | VOs with `__toString()` don't declare `implements Stringable` | E17 |
| 17 | F5 | MED | `CookieController` and `CookieModel` not `final` | E13; E17 |
| 17 | F6 | LOW | `@deprecated` docblock not engine-enforced | E16 |
| 17 | F7 | LOW | `(int) $command->id` / `(bool) $isActiveParam` casts | E13 |
| 17 | F8 | LOW | `CookieView::summarise()` uses static fn closure | E10; E17 |
| 17 | F9 | LOW | `EventOutboxRelay` raw `json_decode` | E17 |
| 17 | F10 | LOW | `EventDispatcher::describeListener()` 19-line branching | E17 (TODO); E18 (cleanup) |
| 17 | G1 | HIGH | Asymmetric visibility on `$id`/`$version` (PHP 8.4) | E16 |
| 17 | G2 | HIGH | `Random\Randomizer` replaces `mt_rand` sampler (PHP 8.4) | E16 |
| 17 | G3 | MED | Property hooks for `assertNotDeleted()` (PHP 8.4) | E16 |
| 17 | G4 | MED | `array_find`/`array_any` adoption in `isDuplicateKey` | E16 |
| 17 | G5 | MED | `new ClassName()->method()` deref-on-new (PHP 8.4) | E16 |
| 17 | G6 | MED | Replace `@deprecated` docblock with `#[\Deprecated]` | E16 |
| 17 | G7 | LOW | Lazy objects for `CookieQueryRepository` hydration (PHP 8.4) | E16 |
| 17 | G8 | LOW | `#[\SensitiveParameter]` on `AuditMiddleware::digestOf` | E16 |
| 17 | G9 | LOW | Native enums for `StockChangeReason` / query-logging level | E05 (query); E16 (StockChangeReason) |
| 17 | G10 | LOW | `mb_trim()` for `CookieName` | E16 |
| 17 | G11 | INFO | `Stringable` as typed parameter constraint | E16 (after F4) |
| 17 | G12 | INFO | Implicit-nullable-type deprecation — Cookie clean | E16 (confirmation) |
| 17 | P1 | LOW | `array_map` with bound `$this` closures (perf) | E17 |
| 17 | P2 | LOW | Hot-path `LOWER(name)` interacts with PHP `strtolower` | E09; E11; E17 |
| 17 | P3 | LOW | `microtime(true)` everywhere; one handler uses `hrtime` | E05; E17 |
| 17 | P4 | INFO | No `eval`/`extract` — verified clean | E18 (note only) |
| 17 | P5 | LOW | `EventOutboxRelay::rehydrate()` uses reflection per row | E17 |
| 18 | F1 | HIGH | No ENGINE / ROW_FORMAT / table charset on any migration | E09 (Cookie); E12 (outbox); follow-up for sibling tables in E18 |
| 18 | F2 | HIGH | `DECIMAL(10,2)` for price loses currency | E09 |
| 18 | F3 | MED | `is_active TINYINT(1)` no CHECK constraint | E09 |
| 18 | F4 | MED | `cookies.tenant_id` is nullable | E09 |
| 18 | F5 | MED | `event_outbox.payload` is `LONGTEXT`, not `JSON` | E12 |
| 18 | F6 | LOW | `cookies.description` `TEXT NULL` no length cap | E12 |
| 18 | F7 | LOW | `users.role` / `users.status` are ENUM | E12 (doc); follow-up to drop column |
| 18 | F8 | INFO | `audit_log.payload_digest` SHA-256 (no payload) | E12 (doc) |
| 18 | F-I1 | HIGH | No leasing index on `event_outbox` | E12 |
| 18 | F-I2 | CRIT | `event_outbox` no `event_uuid` UNIQUE — duplicate delivery | E12 |
| 18 | F-I3 | HIGH | No claim semantics: relay uses plain UPDATE | E12 |
| 18 | F-I4 | MED | `event_outbox` no `tenant_id` | E12 |
| 18 | F-I5 | MED | `audit_log` / `event_outbox` no retention/partitioning | E12 |
| 18 | F-I6 | MED | Soft-delete predicate index missing on `cookies` | E12 (or E09 — assign to E12 for cohesion) |
| 18 | F-I7 | LOW | No FULLTEXT index on `cookies.name` | E12 (doc); ceiling note |
| 18 | F-S1 | HIGH | Composite UNIQUE does NOT prevent two active rows when both NULL tenant | E09 |
| 18 | F-S2 | MED | `deleted_by` paired-but-not-enforced with `deleted_at` | E09 |
| 18 | F-S3 | MED | No `restored_at` / `restored_by` columns | E09 |
| 18 | F-FK1 | HIGH | `cookies` no FK on created_by/updated_by/deleted_by/tenant_id | E09 |
| 18 | F-FK2 | MED | `event_outbox`/`audit_log` no FK on aggregate_id (string) | E12 (doc + type fix) |
| 18 | F-FK3 | LOW | `notifications.user_id` CASCADE may violate GDPR | E09 (decision); E12 (doc) |
| 18 | F-O1..F-O7 | — | Aliases for F-I*/F-O8 (consolidated) | E12 |
| 18 | F-O8 | CRIT | `event_outbox.status VARCHAR(16)` truncates `'unsupported_schema'` | E12 |
| 18 | F-A1 | MED | `audit_log.actor_id` no FK | E12 (doc); follow-up FK |
| 18 | F-A2 | MED | `audit_log` no `entity_type` / `entity_id` | E12 |
| 18 | F-A3 | LOW | `duration_ms DECIMAL(10,2)` caps at ~99M ms | E18 |
| 18 | F-T1 | CRIT | Tests on in-memory SQLite blind MySQL claims | E01 |
| 18 | F-T2 | HIGH | `database.tests.DBPrefix='db_'` in Config but not in `.env` | E03 |
| 18 | F-G1 | HIGH | No hard-delete / `purge()` path for `cookies` (GDPR) | E09 |
| 18 | F-G2 | MED | PII columns not annotated | E12 |
| 18 | F-G3 | MED | No retention/auto-purge | E12 |
| 18 | F-G4 | LOW | No encryption-at-rest documentation | E12; E15 |
| 18 | F-M1 | MED | Migration filename timestamps inconsistent | E15 (doc + scaffolding note) |
| 18 | F-M2 | LOW | CreateCookieReadModel + DropCookieReadModel within 1 day | E09 |
| 18 | F-C1 | HIGH | No `sessionVariables` in Config/Database.php | E03 |
| 18 | F-C2 | HIGH | `strictOn=true` is not pinned strict sql_mode | E03 |
| 18 | F-C3 | HIGH | No isolation level pinned | E03 |
| 18 | P4 | INFO | No `eval`/`extract` — verified clean | E18 (note) |

**Matrix row count:** 242 entries (one per raw finding, including alias rows
for cross-references). Every raw finding from every slice is allocated to
a numbered epic. **No "deferred / out of scope" bucket exists.**

> Note: row labels of the form `12/missing-1..10` and `13/missing-1..14`
> aggregate the missing-test entries from each slice's "Missing tests"
> section; they are all allocated to E18.

---

## Cookie-ready-as-template binary checklist

When every box below is ticked, Cookie is the **template**:

### Phase 0 unblockers (E01 + E02 + E03)
- [ ] `phpunit.xml.dist` no longer carries `force="true"` on `database.tests.DBDriver`.
- [ ] CI runs `composer ci` (incl. `docblocks:audit`, `deptrac`) on every PR.
- [ ] CI runs the test suite on **both** SQLite and MySQL 8 lanes.
- [ ] `phpstan.neon` pins `phpVersion: 80300`; `phpcs.xml` matches.
- [ ] `bin/docblocks-audit` exits 1 on placeholder docblocks.
- [ ] Zero placeholder docblocks remain in Cookie scope.
- [ ] `Config/Database.php` carries `sessionVariables` pinning sql_mode,
  isolation, charset, time_zone. `DBCollat` aligned to
  `utf8mb4_unicode_ci`. `numberNative` is `true`.

### Foundation (E04 + E05 + E06)
- [ ] `app/Domain/Shared/Events/AbstractDomainEvent.php` exists with
  `eventId` (UUIDv7), `occurredAt` (DateTimeImmutable), `actorId`.
- [ ] All 5 Cookie events extend `AbstractDomainEvent`.
- [ ] `CookieStockChangedEvent::$cookieId` is non-nullable `int`.
- [ ] `app/Domain/Shared/Handlers/AbstractCommandHandler.php` and
  `AbstractQueryHandler.php` exist; both expose
  `withLogging(...)` / sampling / log-level escalation.
- [ ] `CommandBus` and `QueryBus` enforce typed `CommandHandlerInterface`
  / `QueryHandlerInterface` (no `method_exists` duck typing).
- [ ] `AbstractQueryHandler` logs slow queries at `warning`, not `info`.
- [ ] `LogSampler` uses `random_int`.
- [ ] `Cookie` implements `AggregateRootInterface`.
- [ ] `Cookie::reconstitute()` rejects `version < 1`.
- [ ] `assignId`/`bumpVersion` require `AggregateHydrator` key.

### Entity lifecycle (E07 + E08)
- [ ] `Cookie::softDelete()` and `Cookie::restore()` exist and raise events.
- [ ] `Cookie::activate()` / `deactivate()` raise events.
- [ ] `ErrorCodes::COOKIE_STATE_NOT_PERSISTED = 403` exists and is used by
  `assertPersisted()`.
- [ ] Every `handle()` method is ≤ 20 lines.
- [ ] All four command handlers emit identical failure-log shapes.
- [ ] `RestoreCookieHandler` uses `DomainException` not `\RuntimeException`;
  logs `duration_ms`; uses camelCase keys.
- [ ] `RestoreCookieCommand` field is `$id` (not `$cookieId`).
- [ ] `CreateCookieHandler::determineErrorCode()` no longer uses
  `str_contains` on exception messages.

### Multi-currency (E09)
- [ ] `CookiePrice` factories require `Currency`.
- [ ] `CookiePrice` bounds recompute per-call from `$currency->decimals`.
- [ ] `cookies` table has `price_minor BIGINT UNSIGNED NOT NULL` +
  `price_currency CHAR(3) NOT NULL`.
- [ ] Migration declares explicit `ENGINE = InnoDB`, `CHARSET = utf8mb4`,
  `COLLATE = utf8mb4_unicode_ci`, `ROW_FORMAT = DYNAMIC`; FKs on
  `created_by`/`updated_by`/`deleted_by`.
- [ ] `Create+Drop` read-model migration pair squashed.
- [ ] `CookieSeeder` dispatches `CreateCookieCommand`.
- [ ] `CookieStock::fromInt` has `MAX_STOCK` overflow guard.
- [ ] `tenant_id` is `NOT NULL DEFAULT 0`.
- [ ] CHECK constraints on `(deleted_at IS NULL) <=> (deleted_by IS NULL)`
  and `is_active IN (0,1)`.
- [ ] `purge(int $id, Actor $actor)` method on `CookieRepository`.

### Read-side + views (E10 + E14)
- [ ] Only one read-side DTO exists (`CookieView` deleted).
- [ ] `CookieDTO` implements `JsonSerializable` with snake_case output.
- [ ] `CookieDTO::id` is non-nullable `int`.
- [ ] `CookieDTO::fromRow(array)` static factory exists.
- [ ] `app/Domain/Shared/DTOs/ReadDTOInterface` exists.
- [ ] `app/Domain/Shared/Services/MoneyFormatter` exists and is used.
- [ ] `app/Language/en/Cookies.php` exists; views use
  `lang('Cookies.…')` for every user-visible string.
- [ ] Every action button gated by `<?php if (can('cookies.…')) ?>`.
- [ ] `partials/_pagination.php` is invoked from `cookies/index.php`.
- [ ] `partials/_empty_state.php` exists and is invoked.

### Repository hygiene (E11)
- [ ] `CookieRepository.php` ≤ 250 LoC (3 collaborators extracted).
- [ ] `existsByName*` no longer calls `withDeleted()` or wraps in `LOWER()`.
- [ ] `delete()` is a single conditional UPDATE checking `affectedRows()`.
- [ ] `restore()` bumps `version` and verifies `affectedRows() === 1`.
- [ ] Search uses `addcslashes($term, '%_\\')` before `like()`.
- [ ] `CookieName::fromTrusted` / `CookiePrice::fromTrusted` exist and
  are used in `toDomainEntity`.

### Outbox + audit (E12)
- [ ] `event_outbox.status` is VARCHAR(32) with CHECK; accepts
  `'unsupported_schema'`.
- [ ] `event_outbox.event_uuid` exists with UNIQUE.
- [ ] `event_outbox` has `reserved_at`, `reserved_by`, `tenant_id`.
- [ ] Relay uses `FOR UPDATE SKIP LOCKED`.
- [ ] `audit_log` has `entity_type`/`entity_id` with index.
- [ ] `PiiRegistry` exists; retention doc lives at `.claude/documentation/RETENTION.md`.

### Provider + HTTP (E13)
- [ ] `CookieServiceProvider` uses constructor injection.
- [ ] `DomainServiceProviderInterface` exposes `registerProjections()`.
- [ ] Route group carries `'filter' => 'web_auth'`; `Filters.php` no
  longer lists `cookies`/`cookies/*` in the deny-list.
- [ ] `CookieController` is `final`; constructor-injected.
- [ ] Controller has generic `catch (\Throwable $e)`.
- [ ] Boolean inputs parsed via `filter_var(..., FILTER_VALIDATE_BOOLEAN)`.

### Test infrastructure (E18)
- [ ] No `tests/Unit/**` file imports `LoggerFactory` (deptrac rule).
- [ ] No `sleep(*)` calls anywhere under `tests/`.
- [ ] `CookieRepositoryTest` is purely real-DB; 8 mocked methods moved
  to `tests/Unit/Domain/Cookie/Repositories/CookieRepositoryErrorMappingTest.php`.
- [ ] `CookieCrudTest` asserts content (cookie names) not view-paths.
- [ ] `seedActiveAdminUser` uses `HashedPassword::fromPlaintext()`;
  `loginAs(User)` and `loginAsCustomer()` helpers exist.
- [ ] New tests: `CookieStockTest`, `MoneyFormatterTest`,
  `CookieAccessorsTest`, `ErrorCodesTest`, `CookieFactoryTest`.
- [ ] New MySQL-conditional integration tests for: idempotent re-save,
  composite-UNIQUE active duplicates, restore-conflict, tenant
  write-isolation, pagination edge cases, SKIP LOCKED concurrency, CSRF
  rejection.

### Documentation (E15)
- [ ] `.claude/skills/domain-scaffolding/SKILL.md` lists every file in
  the current Cookie tree.
- [ ] `COMPLETE_FILE_INVENTORY.md` matches `find app/Domain/Cookie -type f`.
- [ ] `ADDING_DOMAINS.md` points at the regenerated `/add-domain`.
- [ ] `.claude/CLAUDE.md` references this checklist as the
  "template-ready" definition.
- [ ] `.claude/documentation/PROJECTIONS.md` exists.
- [ ] `.claude/documentation/RETENTION.md` exists.
- [ ] `.claude/documentation/PRODUCTION_DEPLOY.md` exists (encryption-at-rest,
  SSL, MySQL operator hand-off).
- [ ] `SERENA_CODE_ANALYSIS_COOKIE_DOMAIN.md` refreshed or deleted.
- [ ] `composer docs:cookie-sync` CI guard fails when Cookie changes
  without scaffolding skill updates.

### PHP 8.3 polish (E17)
- [ ] `#[\Override]` on every interface/parent-method implementation
  (~25 sites).
- [ ] `CookieName` and `CookiePrice` `implements \Stringable`.
- [ ] `CookieController` and `CookieModel` are `final`.
- [ ] `CookieName::MIN_LENGTH` / `MAX_LENGTH` typed const types.
- [ ] All handlers + middleware use `hrtime(true)` for durations.

### PHP 8.4 bump (E16)
- [ ] `composer.json` requires `^8.4`.
- [ ] `phpstan.neon` `phpVersion: 80400`; `phpcs.xml` matches.
- [ ] `Cookie::$id` and `$version` are `public private(set)`.
- [ ] `LogSampler` uses `Random\Randomizer`.
- [ ] `#[\Deprecated]` on any retained legacy methods.
- [ ] `#[\SensitiveParameter]` on `AuditMiddleware::digestOf`.
- [ ] `StockChangeReason` enum used by `Cookie::changeStock()`.

### Final smoke test
- [ ] Manual: run `/add-domain Product` against the regenerated skill.
  Without editing any of the generated files, the new domain passes
  `composer check`.
- [ ] The generated `ProductServiceProvider`, `ProductController`, and
  `Product` entity have **no remaining literal `'Cookie'` strings**,
  **no string-keyed DI**, **no `Services::*` per-action lookups**, and
  **no hand-written timing/logging boilerplate** in handlers.

---

## Specialist review pass

The user has requested every relevant specialist (`ddd-specialist`,
`cqrs-specialist`, `php-specialist`, `clean-code-specialist`,
`codeigniter4-specialist`, `test-specialist`, `phpstan-specialist`,
`slevomat-specialist`, `claude-code-specialist`) review this plan after
it's written. A separate orchestration will dispatch those reviews. This
section just notes the expected reviewer list and what each is expected
to focus on:

- **`ddd-specialist`** — verify themes T1 / T7 / T8 / T9 fixes are
  DDD-correct: aggregate hydrator, lifecycle events, port shape.
- **`cqrs-specialist`** — verify themes T1 / T2 / T4 / T19 fixes: event
  envelope, handler bases, read-side consolidation, outbox idempotency.
- **`php-specialist`** — verify theme T16 / T17: PHPStan phpVersion pin,
  PHP 8.3 idiom completeness, PHP 8.4 adoption sequencing.
- **`clean-code-specialist`** — verify method/class size targets in
  E07/E08/E11, ≤ 20 LoC `handle()` after E05/E08.
- **`codeigniter4-specialist`** — verify themes T10 / T11 / T12 / T18:
  route filter, MySQL CI lane, migration shape, sessionVariables.
- **`test-specialist`** — verify themes T11 / T14: MySQL CI lane,
  coverage close, missing-test enumeration in E18.
- **`phpstan-specialist`** — verify phpVersion pin (E02), bus-enforced
  typed interfaces (E05), zero L8 regressions across the plan.
- **`slevomat-specialist`** — verify `#[\Override]` sniff wiring (E17),
  `RequireFinalClass` enforcement (E17), property-hook / asymmetric-
  visibility sniffs in E16.
- **`claude-code-specialist`** — verify E02 docblocks:audit tightening,
  E15 scaffolding regeneration, `composer docs:cookie-sync` CI guard.

Each reviewer should sign off on the epics that touch their gate before
PR submission.

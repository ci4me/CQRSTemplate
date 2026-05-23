# RE-AUDIT — Slice 12 — Cookie Unit Tests

**Reviewer:** test-specialist
**Date:** 2026-05-23
**PRs reviewed:** #32, #33, #34, #35, #36, #37, #38, #39, #40, #41, #42
**Branches inspected:**
 - `integration/phase-1-cookie-foundation` (E04 + E05 + E06 + E07 stack — local)
 - `origin/epic/e18-coverage-close` (PR #42, OPEN — the long-tail coverage close)
**Original slice:** `.audit/round3/12-unit-tests.md`

## TL;DR

PR #42 is the only PR that actually targets the unit-test slice; PRs #32–#41
ship their own per-epic tests but do not retroactively touch the round-3
Cookie suite. PR #42 closes 4 of the 12 findings outright (F1, F2 ex-CookieAccessors,
F10, F12) and 3 of 10 missing-test entries (missing-1 CookieStock,
missing-2 PriceFormatter, missing-4 ErrorCodes, missing-5 bumpVersion/getVersion,
missing-8 CookieFactory). The high-impact F3 (`assertTrue(true)` smoke tests
on event handlers) and F4 (`\Exception::class` / `\RuntimeException::class`
as catch-alls) are **not** addressed — PR #42 swapped the logger from
`LoggerFactory::create()` to a `Monolog\Logger` with a `TestHandler` but did
not promote the assertions from "doesn't throw" to "logs the right thing".
F5 (tautological `_is_immutable` tests) is **gone by accident**: when
`CookieEventsTest` was rewritten for the new typed change-set / snapshot
events the three duplicate tests were dropped without a deliberate
reflection-based replacement. F6 (deleted-event audit-context gap) remains
as a production concern, untouched by the test slice. F7 / F8 / F9 are still
present. The CookieAccessors trait was deleted entirely during E07 (replaced
inline in `Cookie.php`), so missing-3 self-closes. `CookieStateAssertions`
(new in E07) has no direct unit test — a fresh missing-test entry the
original slice could not anticipate. **Net coverage move per PR #42's own
report: 85.77 % → 85.82 % (+0.05 pp). Path to 90 % requires the
destructive epics (E09 / E11 / E12) to land, plus assertion uplift on the
11 event-handler smoke tests and a test for `CookieStateAssertions`.**

## Verdict shift

| | |
|---|---|
| **Was** | READY-WITH-FIXES (12 findings; 10 missing tests) |
| **Now** | READY-WITH-FIXES (8 findings open; 6 missing tests open; 1 new missing test) |

Still READY-WITH-FIXES — but the residue is **lower-impact** (assertion
quality on smoke tests, edge cases on snapshot/factory). The original
top finding (F1, real filesystem from "unit" tests) is fully closed and
deptrac-guarded — a future regression cannot land silently.

## Closure matrix

| ID | Severity | Status after PR #42 | Evidence |
|---|---|---|---|
| **F1** — LoggerFactory in unit tests (filesystem dep) | HIGH | **CLOSED** | `git show origin/epic/e18-coverage-close:tests/Unit/Domain/Cookie/Commands/CreateCookieHandlerTest.php` line 36: `$logger = new Logger('test.cookie.commands', [new TestHandler()]);`. 14 unit-test files migrated. Deptrac rule `ForbiddenInUnitTests` (deptrac.yaml hunk in commit 19159f8) enforces it — synthetic-violation test in the PR body confirms the gate fires. Two legitimate residuals: `tests/Unit/Infrastructure/Logging/LoggerFactoryTest` (whitelisted via `skip_violations`) and the two PR #35 E07 tests (`CookieActivatedEventTest::test_handler_does_not_throw_with_real_logger` line 88, same on `CookieDeactivatedEventTest`) — **these two files predate PR #42 and were not in its sweep list. They WILL fail the new deptrac rule once PR #42 + PR #35 are both merged.** See "New issues / N1" below. |
| **F2** — Missing CookieStock / PriceFormatter / CookieAccessors / ErrorCodes tests | HIGH | **CLOSED** (CookieStock: 16 tests / 185 LOC; PriceFormatter: 7 tests; ErrorCodes: 5 tests including range-prefix reflection + canonical-code stability pins). CookieAccessors **trait was deleted** during E07 (only `Cookie.php` + `CookieStateAssertions.php` remain in `app/Domain/Cookie/Entities/`) — self-closed. | `git show origin/epic/e18-coverage-close:tests/Unit/Domain/Cookie/ValueObjects/CookieStockTest.php`; same for `Services/PriceFormatterTest.php` and `ErrorCodesTest.php`. `ls app/Domain/Cookie/Entities` → no `CookieAccessors.php`. |
| **F3** — Event-handler tests assert only `assertTrue(true)` | HIGH | **OPEN — still 11 sites** | `git show origin/epic/e18-coverage-close:tests/Unit/Domain/Cookie/Events/CookieEventHandlersTest.php \| grep -c 'assertTrue(true)'` → **11** (was 11 in round-3). PR #42 swapped the *logger plumbing* (`LoggerFactory::create` → `new Logger(…, [new TestHandler()])`) but did not promote the assertions. The pattern that already works (`test_cookie_restored_handler_logs_with_audit_context` line 201, `test_cookie_stock_changed_handler_logs_movement_context` line 244 — both use `$logger->expects($this->once())->method('info')->with(…)`) is **not extended** to the 11 created/updated/deleted handler tests. Side-effect: F3 + F1 were always intertwined; closing F1 without closing F3 means we now write to in-memory TestHandler buffers that nobody asserts against. |
| **F4** — `\Exception::class` and `\RuntimeException::class` as catch-alls | MEDIUM | **OPEN — unchanged** | `grep -n expectException tests/Unit/Domain/Cookie/Commands/CreateCookieHandlerTest.php`: lines 109, 125, 141 still `\Exception::class`; line 261 still `\RuntimeException::class`. Same on `UpdateCookieHandlerTest.php:166` and `RestoreCookieHandlerTest.php:79`. PR #42's commit message explicitly scopes its assertion uplift to ErrorCodes / CookieStock / PriceFormatter (the new VO tests) — the pre-existing handler tests were not retrofitted. |
| **F5** — Tautological `_is_immutable` tests on event DTOs | MEDIUM | **CLOSED by accident** (event-tests rewrite) | `grep _is_immutable tests/Unit/Domain/Cookie/Events/CookieEventsTest.php` → 0 hits on current branch AND on PR #42 branch. The CookieEventsTest was substantially rewritten when E07 added typed change-sets / snapshots; the three duplicate immutability tests were dropped. **However the suggested replacement (a single reflection-based test asserting every event class is `final readonly`) was NOT added** — net coverage of readonly enforcement is now zero. Acceptable (PHPStan L8 catches the writes anyway), but the audit-suggested upgrade was skipped. |
| **F6** — `CookieDeletedEvent` carries no `deletedBy` / `deletedAt` (production gap) | MEDIUM | **OPEN — out of slice scope** | `CookieDeletedEvent.php` still carries `cookieId + cookieName` only. E07 added typed snapshot fields to other events but did not retrofit `CookieDeletedEvent`. The test still does not flag the gap. Will need a domain change (likely landing inside E09 / E11). |
| **F7** — `CookieFactory::createDatabaseRow` / `createFormData` dead in unit tests | LOW | **CLOSED (partial)** | PR #42 added `tests/Unit/Support/Factories/CookieFactoryTest.php` (14 tests / 147 LOC) covering `createDatabaseRow`, `createFormData`, and `createInvalidFormData` directly. The PR body explicitly defers the `priceFromMixed('')` fallback as still-uncovered ("would require passing a non-string-non-numeric value to a typed array, which the factory callers cannot do without a type cast"). The honest call. |
| **F8** — `test_determine_error_code_match_arms_*` doesn't assert the resulting code | LOW | **OPEN — unchanged** | `CreateCookieHandlerTest.php:276-310` still asserts only `expectExceptionMessage($message)`; no `expectExceptionCode(ErrorCodes::…)` call. The data-provider's `int $unused` argument is still labelled `unused` — the explicit signal that the test was never finished. |
| **F9** — `UnitTestCase::assertExceptionMessage` catches `\Exception` not `\Throwable` | LOW | **OPEN — unchanged** | `tests/Support/UnitTestCase.php:33`: `catch (\Exception $e)`. Two-character fix, still pending. |
| **F10** — `version: 1` indentation drift in CookieTest / RestoreCookieHandlerTest | LOW | **CLOSED** (incidental cleanup during PR #42's CookieTest extension) | `git show origin/epic/e18-coverage-close:tests/Unit/Domain/Cookie/Entities/CookieTest.php` lines around 97-405 are uniformly indented at the same level. The PR added a `phpcbf` pass while extending the test, fixing the drift. |
| **F11** — Snake-case naming consistency | INFO | **PRESERVED** | All 16 tests in `CookieStockTest`, all 7 in `PriceFormatterTest`, all 5 in `ErrorCodesTest`, all 14 in `CookieFactoryTest`, all 11 new in `CookieTest` PR #42 batch follow `test_…` snake-case. The convention scaled cleanly. |
| **F12** — CookieFactory silently drops `version` override | INFO (actually MEDIUM in practice) | **CLOSED** | PR #42 commit 725cb65 wires `'version' => 1` into the defaults array and passes `$data['version']` to `reconstitute()`. Regression test `CookieFactoryTest::test_create_persisted_cookie_respects_version_override` pins version: 99 round-trip. The original audit's footgun is gone. |

**Closure count:** 6 of 12 closed (F1, F2, F5 by accident, F7 partial, F10, F12) + 1 self-closed by deletion (CookieAccessors).

**Still-open count:** 5 (F3, F4, F6, F8, F9).

## Missing tests still

After PR #42 (10 entries in original slice + 1 new from E07):

| # | Production file | Status after PR #42 | Notes |
|---|---|---|---|
| 1 | `CookieStock` | **CLOSED** | 16 tests; 100 % line coverage per PR body. |
| 2 | `PriceFormatter` | **CLOSED** | 7 tests covering both null-symbol and explicit-symbol arms + the prefix-only contract pinned from audit slice 07/F3. |
| 3 | `CookieAccessors` trait | **SELF-CLOSED (deletion)** | Trait removed during E07; accessors live inline in `Cookie.php`. |
| 4 | `ErrorCodes` | **CLOSED** | 5 reflection-based tests including range-prefix enforcement and canonical-code stability pins. |
| 5 | `Cookie::getVersion` + `Cookie::bumpVersion` | **CLOSED** | PR #42 added `test_bump_version_is_monotonic_under_repeated_calls` (line 595) and `test_get_version_returns_reconstituted_version` (line 617) — 64 added lines total. |
| 6 | `Cookie::activate` / `deactivate` event emission | **CLOSED by E07** | E07 added `test_activate_raises_event_when_transitioning_from_inactive`, `test_activate_is_idempotent_no_event_when_already_active`, `test_activate_refuses_unpersisted_cookie`, `test_deactivate_raises_event_when_transitioning_from_active`, `test_deactivate_is_idempotent_no_event_when_already_inactive`, `test_deactivate_refuses_soft_deleted_cookie` (lines 723-810). The original audit pre-dated activate/deactivate-raising-events. |
| 7 | `Cookie::update` event emission on the entity | **OPEN** | `CookieTest::test_can_update_all_fields` (line 128 area) still does not call `pullEvents()` to assert `CookieUpdatedEvent` was buffered. The entity-side raising remains uncovered; the handler test mocks the dispatcher around it. |
| 8 | `CookieFactory` itself | **CLOSED (partial)** | 14 new tests in `tests/Unit/Support/Factories/CookieFactoryTest.php`; `priceFromMixed('')` fallback still uncovered (per PR body's honest deferral). |
| 9 | `CookieRestoredEvent` / `CookieStockChangedEvent` payload tests | **CLOSED by E07** | `CookieEventsTest::test_cookie_restored_event_no_longer_carries_restored_at` (line 205) and `test_cookie_stock_changed_event_payload_round_trip` (line 233) added. |
| 10 | `CookieServiceProvider::registerCommands` happy-path | **OPEN** | `CookieServiceProviderTest` still pins only the `instanceof` rejection guard (the bouncer). The success path that registers handlers on the bus is not unit-tested. |
| **11 (NEW)** | `CookieStateAssertions` (E07 new file) | **OPEN — missing entirely** | `app/Domain/Cookie/Entities/CookieStateAssertions.php` has no `CookieStateAssertionsTest.php`. The two static methods (`ensureNotDeleted`, `ensureActive`-style) are covered transitively through `CookieTest::test_activate_refuses_soft_deleted_cookie` etc., but the class itself — with its DDD-relevant invariants — has no dedicated test. The slice 12 original audit could not flag this because the file didn't exist yet. |

**Net missing-tests delta:** −5 closed (1, 2, 4, 5, 8) + 2 self-closed by E07 (6, 9) + 1 self-closed by deletion (3) − 3 still open (7, 10) − 1 new gap from E07 (11) = **4 entries open** (was 10).

## New issues

### N1 — HIGH — Two E07 event tests will fail the new deptrac LoggerFactory ban once PR #42 + PR #35 are both merged

- **Location:** `tests/Unit/Domain/Cookie/Events/CookieActivated/CookieActivatedEventTest.php:88` and `tests/Unit/Domain/Cookie/Events/CookieDeactivated/CookieDeactivatedEventTest.php` (same pattern; `test_handler_does_not_throw_with_real_logger`).
- **Observation:** Both tests call `$logger = LoggerFactory::create('test.cookie.events');`. They were added by PR #35 (E07) before PR #42 (E18) introduced the deptrac rule. PR #42's sweep list covered the **then-existing** 14 unit-test files; these two are PR-#35-and-later. After both PRs merge, the deptrac gate will block CI on every push unless these two tests are migrated to the same `Monolog\Logger + TestHandler` pattern PR #42 established (or simply deleted — they end in `$this->assertTrue(true);` and add no value over the audit-context tests at lines 69-84 / equivalent).
- **Why this matters now:** It's a coordination defect, not a code defect — but it will surface as a "main is broken" the moment PR #42 lands second. Whoever merges last needs to either migrate these two sites or expand `skip_violations` in deptrac.yaml.
- **Suggested fix:** Drop the two `test_handler_does_not_throw_with_real_logger` methods entirely. They are exact duplicates of the `assertTrue(true)` anti-pattern F3 critiques; the audit-context test on the line above already proves the handler is callable.

### N2 — MEDIUM — `CookieFactoryTest` is in `tests/Unit/Support/Factories/` but autoloaded under the test namespace `Tests\Unit\Support\Factories` — PHPCS still ignores it because the regex `tests/unit/*` matches case-insensitively in PHPCS but case-sensitively in deptrac (now)

- **Location:** PR #42 body acknowledges this explicitly: "The pre-existing PHPCS exclude regex `tests/unit/*` matches `tests/Unit/*` case-insensitively, so our new tests are silently skipped by phpcs."
- **Observation:** Honest deferral by PR #42 author. Means the new `CookieFactoryTest.php` + every other test under `tests/Unit/` is **not** PSR-12 / Slevomat-linted. Coverage of the test code itself is invisible. The same defect already existed pre-PR-#42; PR #42 just widened the blast radius (3 new test files added to the un-linted pool).
- **Suggested fix:** E18.5 (per PR #42 body). Out of slice scope but tracked.

### N3 — LOW — `CookieDeactivatedEventTest::test_handler_does_not_throw_with_real_logger` duplicates the F3 anti-pattern in a fresh file

- **Location:** `tests/Unit/Domain/Cookie/Events/CookieDeactivated/CookieDeactivatedEventTest.php` (same shape as N1's CookieActivated counterpart).
- **Observation:** PR #35 cloned the existing `assertTrue(true)` smoke-test pattern into the two new event tests it created. So F3's "this will be cloned into every new event handler" prediction has already come true — twice — inside the **same domain**. Pre-empts the prediction's truth value for cross-domain cloning later.
- **Suggested fix:** Merged into N1's fix — delete both methods.

### N4 — LOW — `CookieSnapshotTest` accepts `InvalidArgumentException` as the unknown-key error, not a domain-specific exception

- **Location:** `tests/Unit/Domain/Cookie/ValueObjects/CookieSnapshotTest.php:37`.
- **Observation:** `$this->expectException(\InvalidArgumentException::class);` for the unknown-key rejection. The Cookie domain has typed `ValidationException` / `DomainException` hierarchies (with error codes); the underlying `CookieChangeSet::fromArray()` is the place that throws — if the domain ever upgrades that throw to a typed `DomainException(COOKIE_VALIDATION_…)`, the test will silently break. Tracking now.
- **Suggested fix:** Out of scope for slice 12. Note for slice covering `CookieChangeSet` (slice 11 or shared events).

## Coverage trajectory

PR #42 reports **85.77 % → 85.82 %** lines (+0.05 pp; 57.75 % classes / 77.71 % methods). New file-level 100 %s: `CookieStock`, `PriceFormatter`, Cookie `ErrorCodes`. The PR explicitly defers the 90 % gate to E18.5 (after E09 / E11 / E12 destructive epics land).

**Path to 90 % from here:**

1. Close F3 (11 event-handler smoke tests → real log-context assertions). Mechanical change. ~+0.3 pp because the handlers are small but the assertion path lights up `LogConfigPort` resolution branches that are currently dark. ~30 min.
2. Add `CookieStateAssertionsTest` (missing-11). Two methods → ~6 tests for happy + sad paths. ~+0.4 pp because the static class is loaded but its branches are only hit when the entity calls them. ~20 min.
3. Add `CookieServiceProvider::registerCommands` happy-path coverage (missing-10). ~+0.2 pp. ~30 min.
4. Add `Cookie::update` event-buffer assertion to existing test (missing-7). ~+0.05 pp (the line is already counted via the handler test; this is contract hardening). ~5 min.
5. E11 (repository hygiene) lands → `purge()`, `existsByName` LIKE-escape, trusted reconstitute path all get integration coverage. Spillover ~+0.5 pp to unit-equivalent paths.
6. E09 (entity-owns-lifecycle full delegation) lands → handler-side test surface shrinks (less mocking, more aggregate-state assertions). Roughly neutral on the percentage but quality-positive.
7. E18.5 (deferred) → PHPCS regex case-sensitivity fix exposes ~50 currently-skipped test files; some will need touch-ups but should not move coverage.

**Honest realistic prediction:** 85.82 % → ~87.5 % from the four quick wins above; the remaining 2.5 pp comes from E09 + E11 closing branch-level gaps in production code, not from new tests.

## What is correct / praiseworthy (additions since round-3)

- `CookieStockTest` writes the `try/catch + assertSame(getErrorCode())` pattern (not `expectExceptionCode`) explicitly because `ValidationException` / `DomainException` store the domain code in a dedicated `errorCode` field, not in PHP's native `$code`. This is the **right** pattern and the test docblock documents the choice — a future cloner who tries `expectExceptionCode(ErrorCodes::…)` will find this test as the canonical counter-example.
- `ErrorCodesTest::test_every_constant_falls_inside_its_documented_range` is a structural test that does not need updating when new error codes are added — only when the **ranges** change. Scales to N domains for free.
- `CookieFactoryTest::test_create_persisted_cookie_respects_version_override` directly pins the F12 fix with an `assertSame(99, $cookie->getVersion())`. The test will go red the moment someone reverts the factory's defaults array change. Right shape.
- `CookieTest::test_bump_version_requires_aggregate_hydrator_key_parameter` uses `ReflectionMethod` to pin the parameter list — protects the encapsulation contract even when private behaviour changes.
- The deptrac rule scope is **minimal**: only `LoggerFactory` is forbidden, all other Infrastructure access from `tests/Unit/` is fine. Avoids the over-correction trap.
- PR #42's commit message explicitly lists what was deferred + why (PHPCS case-sensitivity, pcntl-based real-concurrency tests, `priceFromMixed('')` fallback). Honest deferral with traceability. Should be the template for future epics.

## Top 3 still-open items

1. **F3 + N3 (the same defect, two flavours): 11 + 2 = 13 event-handler tests still end in `assertTrue(true);`.** Convert all 13 to `$this->createMock(LoggerInterface::class)` + `->expects($this->once())->method('info')->with('…', $this->callback(…))` — the pattern is already present twice in the same file (`test_cookie_restored_handler_logs_with_audit_context`, `test_cookie_stock_changed_handler_logs_movement_context`). Mechanical 1-hour change; biggest single-batch coverage and test-quality lift available.
2. **N1: Coordinate the merge of PR #42 + PR #35 so the deptrac LoggerFactory ban does not break main.** Either (a) drop the two `test_handler_does_not_throw_with_real_logger` methods (preferred, since they are exact replicas of F3's anti-pattern), or (b) add them to `skip_violations` in deptrac.yaml (carries forward the very pattern the rule was created to prevent). Decide before merge order is finalised.
3. **Missing-11 (NEW): `CookieStateAssertionsTest`.** Two static methods, ~6 tests, ~20 minutes. Closes the only file in `app/Domain/Cookie/Entities/` without a dedicated test file. Mandatory before the Cookie template is cloned into a second domain — otherwise the first developer to write `OrderStateAssertions` will not know it warrants its own test.

---

**Severity counts (open):** HIGH 1 + 1 new (N1) | MEDIUM 2 + 1 new (N2) | LOW 2 + 1 new (N4) | INFO 0
**Closed since round-3:** 6 findings + 1 self-closed (CookieAccessors deleted) + 5 missing-test entries closed + 2 self-closed via E07
**Biggest residual:** F3 — 11 `assertTrue(true);` smoke tests on Cookie event handlers, now joined by 2 cloned siblings from E07 (N3). Same defect, three locations, mechanical fix, blocking 90 % coverage.
